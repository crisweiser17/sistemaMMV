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
use App\Services\AlteracaoService;
use App\Services\AnexoService;
use App\Services\EngenhariaService;
use App\Services\GanttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EngenhariaController extends Controller
{
    public function __construct(
        private EngenhariaService $service,
        private AlteracaoService $alteracoes,
    ) {}

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
        $unidadeId = $request->integer('unidade_id');
        $statusId = $request->integer('status_id');
        $responsavelId = $request->integer('responsavel_id');

        // Filtro de headers (itens): autorizacao + busca/cliente/unidade/status/responsavel.
        // Aplicado no whereHas (quais cotacoes aparecem) e no eager-load (quais itens mostrar).
        $filtroHeaders = function ($q) use ($apenasDoUsuario, $user, $busca, $clienteId, $unidadeId, $statusId, $responsavelId) {
            if ($apenasDoUsuario) {
                $q->where('responsavel_id', $user->id);
            } elseif ($responsavelId) {
                $q->where('responsavel_id', $responsavelId);
            }
            if ($clienteId) {
                $q->where('cliente_id', $clienteId);
            }
            if ($unidadeId) {
                $q->where('unidade_id', $unidadeId);
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
                // Carrega as duas origens possiveis do item (PI e cotacao): so uma delas vem preenchida.
                'headers' => fn ($q) => $filtroHeaders($q)->with(['status', 'cliente', 'unidade', 'itemCotacao.unidade', 'itemLiberacao.unidade', 'itemLiberacao.liberacao']),
            ])
            ->latest()->get();

        // Marcacao "ALTERADO" resolvida em lote para a lista inteira (ver AlteracaoService).
        $marcadas = $this->alteracoes->marcadas($demandas);

        $data = $demandas->map(function ($d) use ($marcadas) {
            $primeiro = $d->headers->first();

            return [
                'id' => $d->id,
                'tipo' => $d->tipo,
                'numero_referencia' => $primeiro?->numero_referencia ?? ('Demanda #'.$d->id),
                'cliente' => $primeiro?->cliente_com_unidade,
                'qtd_itens' => $d->headers->count(),
                'detalhar_url' => route('engenharia.demanda', $d),
                'alterado' => $marcadas[$d->id] ?? false,
                'alteracoes_url' => route('output.alteracoes', $d),
                'itens' => $d->headers->map(function ($h) {
                    $item = $h->dadosItemOrigem();

                    return [
                        'nome_item' => $h->nome_item,
                        'cod_mmv' => $item['cod_mmv'],
                        'quantidade' => $item['quantidade'],
                        'unidade' => $item['unidade'],
                        'status' => $h->status ? ['nome' => $h->status->nome, 'cor_hex' => $h->status->cor_hex] : null,
                    ];
                })->values(),
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
            ->with(['status', 'cliente', 'unidade', 'itemCotacao.unidade', 'itemLiberacao.unidade', 'itemLiberacao.liberacao'])
            ->orderBy('id')
            ->get();

        abort_if($headers->isEmpty(), 403);

        $referencia = $demanda->referencia();

        return view('engenharia.demanda', [
            'demanda' => $demanda,
            'headers' => $headers,
            'numeroReferencia' => $headers->first()->numero_referencia,
            // Rotulo "Cliente – Unidade" do header; cai para o PI/cotacao quando o header nao tem cliente.
            'clienteRotulo' => $headers->first()->cliente_com_unidade ?? $referencia?->cliente_com_unidade,
            'escopos' => Escopo::orderBy('descricao')->pluck('descricao', 'id'),
            'unidades' => UnidadeMedida::orderBy('sigla')->pluck('sigla', 'id'),
            'categorias' => CategoriaComponente::orderBy('nome')->get(),
            // Atalho para o historico aparece so quando ha alteracao pos-PDF.
            'alteracoes' => $this->alteracoes->mapa($demanda),
        ]);
    }

    /** JSON: linhas do header (liveResource). */
    public function linhas(EngenhariaHeader $header): JsonResponse
    {
        $linhas = $header->linhas()->with(['material.tipo.categoria', 'dependencias:id,numero_linha'])->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'numero_linha' => $l->numero_linha,
                'cod_mmv' => $l->cod_mmv,
                'descricao' => $l->descricao,
                'tipo_componente' => $l->tipo_componente,
                // IDs alem dos rotulos: o formulario precisa deles para remontar os selects encadeados na edicao.
                'categoria_componente_id' => $l->categoria_componente_id,
                'tipo_componente_id' => $l->tipo_componente_id,
                'material_id' => $l->material_id,
                // Rotulo completo vindo do Cadastro (categoria, dimensoes e norma juntas).
                'material' => $l->material?->especificacao_completa,
                'mao_de_obra' => $l->mao_de_obra,
                'quantidade' => $l->quantidade,
                'duracao_dias' => $l->duracao_dias,
                // Observacao livre da linha: aparece na tela e e impressa na folha de processo.
                'observacao' => $l->observacao,
                'fase' => $l->fase,
                'status' => $l->status,
                'arquivo_nome' => $l->arquivo_path ? AnexoService::nomeOriginal($l->arquivo_path) : null,
                'dependencias' => $l->dependencias->pluck('numero_linha'),
            ]);

        return response()->json(['data' => $linhas]);
    }

    public function addLinha(Request $request, EngenhariaHeader $header): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        $linha = $this->service->adicionarLinha($header, $this->validar($request));
        $this->aplicarDependencias($request, $linha);

        return response()->json(['ok' => true, 'id' => $linha->id]);
    }

    public function updLinha(Request $request, EngenhariaHeader $header, EngenhariaLinha $linha): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);

        $this->service->atualizarLinha($linha, $this->validar($request));
        $this->aplicarDependencias($request, $linha);

        return response()->json(['ok' => true]);
    }

    /**
     * Dependencias informadas como lista de numeros de linha (ex.: "2,3").
     * Vale tanto na criacao quanto na edicao — o formulario manda o campo nas duas.
     * Campo presente e vazio limpa as dependencias; campo ausente nao mexe nelas.
     */
    private function aplicarDependencias(Request $request, EngenhariaLinha $linha): void
    {
        if (! $request->has('dependencias')) {
            return;
        }

        $numeros = array_filter(array_map('intval', explode(',', (string) $request->input('dependencias'))));

        $this->service->definirDependenciasPorNumeros($linha, $numeros);
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

    /** JSON: itens concluidos que podem servir de molde para a copia de estrutura. */
    public function estruturas(Request $request, EngenhariaHeader $header): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        $dados = $request->validate(['busca' => 'nullable|string|max:100']);

        return response()->json([
            'data' => $this->service->estruturasCopiaveis($header, (string) ($dados['busca'] ?? '')),
        ]);
    }

    /** Copia as linhas (e dependencias) de um item concluido para este item. */
    public function copiarEstrutura(Request $request, EngenhariaHeader $header): JsonResponse
    {
        $this->authorize('editar', 'engenharia');
        $dados = $request->validate([
            // notIn: copiar do proprio item duplicaria as linhas em vez de reaproveitar outra estrutura.
            'origem_id' => ['required', 'integer', 'exists:engenharia_headers,id', Rule::notIn([$header->id])],
            'modo' => ['required', Rule::in([EngenhariaService::MODO_ACRESCENTAR, EngenhariaService::MODO_SUBSTITUIR])],
        ]);

        $origem = EngenhariaHeader::findOrFail($dados['origem_id']);
        $copiadas = $this->service->copiarEstrutura($header, $origem, $dados['modo']);

        return response()->json(['ok' => true, 'linhas' => $copiadas]);
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
        $request->validate(AnexoService::regras(), AnexoService::mensagens());

        $this->service->anexarArquivoLinha($linha, $request->file('arquivo'));

        return response()->json(['ok' => true]);
    }

    /** Serve o arquivo da linha inline (preview/abrir em nova aba). */
    public function verArquivo(EngenhariaHeader $header, EngenhariaLinha $linha): StreamedResponse
    {
        $this->authorize('ver', 'engenharia');
        abort_unless($linha->header_id === $header->id, 404);
        abort_unless($linha->arquivo_path && AnexoService::disco()->exists($linha->arquivo_path), 404);

        return AnexoService::disco()->response($linha->arquivo_path, AnexoService::nomeOriginal($linha->arquivo_path));
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
