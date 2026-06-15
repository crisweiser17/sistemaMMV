<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Escopo;
use App\Models\Liberacao;
use App\Models\LiberacaoAnexo;
use App\Models\LiberacaoItem;
use App\Models\LiberacaoItemAnexo;
use App\Models\UnidadeMedida;
use App\Services\LiberacaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiberacaoController extends Controller
{
    public function __construct(private LiberacaoService $service) {}

    public function index(Request $request): View
    {
        $liberacoes = Liberacao::query()
            ->with(['cliente', 'escopo'])
            ->when($request->filled('cliente_id'), fn ($q) => $q->where('cliente_id', $request->integer('cliente_id')))
            ->when($request->filled('busca'), fn ($q) => $q->where('numero_pi', 'like', '%'.$request->string('busca').'%'))
            ->latest()->paginate(15)->withQueryString();

        return view('liberacao.index', [
            'liberacoes' => $liberacoes,
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('editar', 'liberacao');

        return view('liberacao.form', $this->formData(new Liberacao));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        [$dados, $itens] = $this->validar($request);

        $liberacao = $this->service->criar($dados, $itens, $request->user()->id);

        return redirect()->route('liberacao.show', $liberacao)->with('success', 'PI criado e demanda gerada.');
    }

    public function show(Liberacao $liberacao): View
    {
        $liberacao->load(['cliente', 'escopo', 'itens.unidade', 'itens.anexos', 'anexos', 'autor']);

        return view('liberacao.show', compact('liberacao'));
    }

    public function edit(Liberacao $liberacao): View
    {
        $this->authorize('editar', 'liberacao');
        $liberacao->load('itens');

        return view('liberacao.form', $this->formData($liberacao));
    }

    public function update(Request $request, Liberacao $liberacao): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        [$dados, $itens] = $this->validar($request);

        $this->service->atualizar($liberacao, $dados, $itens);

        return redirect()->route('liberacao.show', $liberacao)->with('success', 'PI atualizado.');
    }

    public function anexoItem(Request $request, Liberacao $liberacao, LiberacaoItem $item): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        abort_unless($item->liberacao_id === $liberacao->id, 404);
        $request->validate(['arquivo' => 'required|file|max:20480']);

        $this->service->adicionarAnexoItem($item, $request->file('arquivo'));

        return back()->with('success', 'Anexo do item enviado.');
    }

    public function anexo(Request $request, Liberacao $liberacao): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        $request->validate(['arquivo' => 'required|file|max:20480']);

        $this->service->adicionarAnexoGeral($liberacao, $request->file('arquivo'));

        return back()->with('success', 'Anexo enviado.');
    }

    /** Serve o anexo geral do PI inline (preview/abrir em nova aba). */
    public function verAnexo(Liberacao $liberacao, LiberacaoAnexo $anexo): StreamedResponse
    {
        $this->authorize('ver', 'liberacao');
        abort_unless($anexo->liberacao_id === $liberacao->id, 404);
        abort_unless(Storage::exists($anexo->path), 404);

        return Storage::response($anexo->path, $anexo->nome_arquivo);
    }

    public function removeAnexo(Liberacao $liberacao, LiberacaoAnexo $anexo): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        abort_unless($anexo->liberacao_id === $liberacao->id, 404);

        $this->service->removerAnexo($anexo);

        return back()->with('success', 'Anexo removido.');
    }

    /** Serve o anexo de um item inline (preview/abrir em nova aba). */
    public function verAnexoItem(Liberacao $liberacao, LiberacaoItemAnexo $anexo): StreamedResponse
    {
        $this->authorize('ver', 'liberacao');
        abort_unless($anexo->item?->liberacao_id === $liberacao->id, 404);
        abort_unless(Storage::exists($anexo->path), 404);

        return Storage::response($anexo->path, $anexo->nome_arquivo);
    }

    public function removeAnexoItem(Liberacao $liberacao, LiberacaoItemAnexo $anexo): RedirectResponse
    {
        $this->authorize('editar', 'liberacao');
        abort_unless($anexo->item?->liberacao_id === $liberacao->id, 404);

        $this->service->removerAnexoItem($anexo);

        return back()->with('success', 'Anexo removido.');
    }

    private function formData(Liberacao $liberacao): array
    {
        return [
            'liberacao' => $liberacao,
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
            'escopos' => Escopo::orderBy('descricao')->pluck('descricao', 'id'),
            'unidades' => UnidadeMedida::orderBy('sigla')->pluck('sigla', 'id'),
        ];
    }

    /** @return array{0: array, 1: array} */
    private function validar(Request $request): array
    {
        $validated = $request->validate([
            'numero_pi' => 'nullable|string|max:100',
            'numero_pc' => 'nullable|string|max:100',
            'cliente_id' => 'nullable|exists:clientes,id',
            'escopo_id' => 'nullable|exists:escopos,id',
            'data_pedido' => 'nullable|date',
            'nf_cliente' => 'nullable|string|max:100',
            'data_entrega_cliente' => 'nullable|date',
            'observacoes' => 'nullable|string',
            'itens' => 'array',
            'itens.*.descricao' => 'nullable|string',
            'itens.*.quantidade' => 'nullable|numeric|min:0',
            'itens.*.prazo_entrega_item' => 'nullable|integer|min:0',
        ]);

        $itens = $validated['itens'] ?? [];
        unset($validated['itens']);

        return [$validated, $itens];
    }
}
