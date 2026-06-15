<?php

namespace App\Http\Controllers;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Escopo;
use App\Models\StatusEngenharia;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Services\EngenhariaService;
use App\Services\GanttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EngenhariaController extends Controller
{
    public function __construct(private EngenhariaService $service) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $apenasDoUsuario = ! $user->temPerfil('Administrador');

        // Grid reativo: a listagem vem do endpoint JSON data(); aqui so as opcoes dos filtros.
        return view('engenharia.index', [
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
            'statuses' => StatusEngenharia::orderBy('ordem')->get(),
            'responsaveis' => $apenasDoUsuario ? null : User::where('ativo', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** Endpoint JSON (motor) consumido pelo grid reativo liveResource. Agrupa por demanda/cotacao. */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $apenasDoUsuario = ! $user->temPerfil('Administrador');

        $busca = trim((string) $request->string('busca'));
        $clienteId = $request->integer('cliente_id');
        $statusId = $request->integer('status_id');
        $responsavelId = $request->integer('responsavel_id');

        // Filtro de headers (itens): autorizacao + busca/cliente/status/responsavel.
        // Aplicado no whereHas (quais cotacoes aparecem) e no eager-load (quais itens mostrar).
        $filtroHeaders = function ($q) use ($apenasDoUsuario, $user, $busca, $clienteId, $statusId, $responsavelId) {
            if ($apenasDoUsuario) {
                $q->where('responsavel_id', $user->id);
            } elseif ($responsavelId) {
                $q->where('responsavel_id', $responsavelId);
            }
            if ($clienteId) {
                $q->where('cliente_id', $clienteId);
            }
            if ($statusId) {
                $q->where('status_id', $statusId);
            }
            if ($busca !== '') {
                $q->where(fn ($w) => $w->where('numero_referencia', 'like', '%'.$busca.'%')
                    ->orWhere('nome_item', 'like', '%'.$busca.'%'));
            }

            return $q;
        };

        $demandas = Demanda::query()
            ->whereHas('headers', $filtroHeaders)
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->with([
                'headers' => fn ($q) => $filtroHeaders($q)->with(['status', 'cliente', 'itemCotacao.unidade']),
            ])
            ->latest()->get();

        $data = $demandas->map(function ($d) {
            $primeiro = $d->headers->first();

            return [
                'id' => $d->id,
                'tipo' => $d->tipo,
                'numero_referencia' => $primeiro?->numero_referencia ?? ('Demanda #'.$d->id),
                'cliente' => $primeiro?->cliente?->nome,
                'qtd_itens' => $d->headers->count(),
                'detalhar_url' => route('engenharia.demanda', $d),
                'itens' => $d->headers->map(fn ($h) => [
                    'nome_item' => $h->nome_item,
                    'cod_mmv' => $h->itemCotacao?->cod_mmv,
                    'quantidade' => $h->itemCotacao?->quantidade,
                    'unidade' => $h->itemCotacao?->unidade?->sigla,
                    'status' => $h->status ? ['nome' => $h->status->nome, 'cor_hex' => $h->status->cor_hex] : null,
                ])->values(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** Tela de detalhamento de uma cotacao/demanda: seletor de item + linhas por item. */
    public function demanda(Request $request, Demanda $demanda): View
    {
        $user = $request->user();
        $apenasDoUsuario = ! $user->temPerfil('Administrador');

        $headers = $demanda->headers()
            ->when($apenasDoUsuario, fn ($q) => $q->where('responsavel_id', $user->id))
            ->with(['status', 'cliente', 'itemCotacao.unidade'])
            ->orderBy('id')
            ->get();

        abort_if($headers->isEmpty(), 403);

        $referencia = $demanda->referencia();

        return view('engenharia.demanda', [
            'demanda' => $demanda,
            'headers' => $headers,
            'numeroReferencia' => $headers->first()->numero_referencia,
            'cliente' => $headers->first()->cliente ?? $referencia?->cliente,
            'escopos' => Escopo::orderBy('descricao')->pluck('descricao', 'id'),
            'unidades' => UnidadeMedida::orderBy('sigla')->pluck('sigla', 'id'),
            'categorias' => CategoriaComponente::orderBy('nome')->get(),
        ]);
    }

    /** JSON: linhas do header (liveResource). */
    public function linhas(EngenhariaHeader $header): JsonResponse
    {
        $linhas = $header->linhas()->with(['material', 'dependencias:id,numero_linha'])->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'numero_linha' => $l->numero_linha,
                'cod_mmv' => $l->cod_mmv,
                'descricao' => $l->descricao,
                'tipo_componente' => $l->tipo_componente,
                'material' => $l->material?->descricao,
                'mao_de_obra' => $l->mao_de_obra,
                'quantidade' => $l->quantidade,
                'duracao_dias' => $l->duracao_dias,
                'fase' => $l->fase,
                'status' => $l->status,
                'arquivo_nome' => $l->arquivo_path ? \Illuminate\Support\Str::after(basename($l->arquivo_path), '_') : null,
                'dependencias' => $l->dependencias->pluck('numero_linha'),
            ]);

        return response()->json(['data' => $linhas]);
    }

    public function addLinha(Request $request, EngenhariaHeader $header): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        $linha = $this->service->adicionarLinha($header, $this->validar($request));

        return response()->json(['ok' => true, 'id' => $linha->id]);
    }

    public function updLinha(Request $request, EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);

        $this->service->atualizarLinha($linha, $this->validar($request));

        // Dependencias informadas como lista de numeros de linha (ex.: "2,3")
        if ($request->filled('dependencias')) {
            $numeros = array_filter(array_map('intval', explode(',', (string) $request->input('dependencias'))));
            $this->service->definirDependenciasPorNumeros($linha, $numeros);
        }

        return response()->json(['ok' => true]);
    }

    public function delLinha(EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);

        $this->service->removerLinha($linha);

        return response()->json(['ok' => true]);
    }

    public function addDep(Request $request, EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);
        $request->validate(['depende_de_linha_id' => 'required|exists:engenharia_linhas,id']);

        $this->service->adicionarDependencia($linha, $request->integer('depende_de_linha_id'));

        return response()->json(['ok' => true]);
    }

    public function finalizar(EngenhariaHeader $header): RedirectResponse
    {
        $this->authorize('editar', 'engenharia');
        $this->service->finalizar($header);

        return back()->with('success', 'Item finalizado.');
    }

    public function gantt(EngenhariaHeader $header, GanttService $gantt): JsonResponse
    {
        return response()->json($gantt->dados($header));
    }

    public function upload(Request $request, EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);
        $request->validate(['arquivo' => 'required|file|max:20480']);

        $this->service->anexarArquivoLinha($linha, $request->file('arquivo'));

        return response()->json(['ok' => true]);
    }

    /** Serve o arquivo da linha inline (preview/abrir em nova aba). */
    public function verArquivo(EngenhariaHeader $header, EngenhariaLinha $linha): StreamedResponse
    {
        $this->authorize('ver', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);
        abort_unless($linha->arquivo_path && Storage::exists($linha->arquivo_path), 404);

        return Storage::response($linha->arquivo_path, \Illuminate\Support\Str::after(basename($linha->arquivo_path), '_'));
    }

    public function removerArquivo(EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);

        $this->service->removerArquivoLinha($linha);

        return response()->json(['ok' => true]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'cod_mmv' => 'nullable|string|max:100',
            'descricao' => 'nullable|string',
            'local_referencia' => 'nullable|string|max:255',
            'escopo_id' => 'nullable|exists:escopos,id',
            'tipo_componente' => 'nullable|in:materia_prima,servico,comercial',
            'categoria_componente_id' => 'nullable|exists:categorias_componente,id',
            'tipo_componente_id' => 'nullable|exists:tipos_componente,id',
            'material_id' => 'nullable|exists:materiais,id',
            'mao_de_obra' => 'nullable|string|max:255',
            'quantidade' => 'nullable|numeric|min:0',
            'duracao_dias' => 'nullable|integer|min:1',
            'unidade_id' => 'nullable|exists:unidades_medida,id',
            'observacao' => 'nullable|string',
            'fase' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:100',
        ]);
    }
}
