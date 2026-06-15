<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\CotacaoAnexo;
use App\Models\Escopo;
use App\Models\UnidadeMedida;
use App\Services\CotacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CotacaoController extends Controller
{
    public function __construct(private CotacaoService $service) {}

    public function index(Request $request): View
    {
        $cotacoes = Cotacao::query()
            ->with(['cliente', 'escopo'])
            ->when($request->filled('cliente_id'), fn ($q) => $q->where('cliente_id', $request->integer('cliente_id')))
            ->when($request->filled('busca'), fn ($q) => $q->where('numero', 'like', '%'.$request->string('busca').'%'))
            ->latest()->paginate(15)->withQueryString();

        return view('cotacao.index', [
            'cotacoes' => $cotacoes,
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('editar', 'cotacao');

        return view('cotacao.form', $this->formData(new Cotacao));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('editar', 'cotacao');
        [$dados, $itens] = $this->validar($request);

        $cotacao = $this->service->criar($dados, $itens, $request->user()->id);

        return redirect()->route('cotacao.show', $cotacao)->with('success', 'Cotação criada e demanda gerada.');
    }

    public function show(Cotacao $cotacao): View
    {
        $cotacao->load(['cliente', 'escopo', 'itens.unidade', 'anexos', 'autor']);

        return view('cotacao.show', compact('cotacao'));
    }

    public function edit(Cotacao $cotacao): View
    {
        $this->authorize('editar', 'cotacao');
        $cotacao->load('itens');

        return view('cotacao.form', $this->formData($cotacao));
    }

    public function update(Request $request, Cotacao $cotacao): RedirectResponse
    {
        $this->authorize('editar', 'cotacao');
        [$dados, $itens] = $this->validar($request);

        $this->service->atualizar($cotacao, $dados, $itens);

        return redirect()->route('cotacao.show', $cotacao)->with('success', 'Cotação atualizada.');
    }

    /** AJAX: historico por NI (consumido pela fabrica Alpine niLookup). */
    public function buscaNi(Request $request): JsonResponse
    {
        $request->validate(['ni' => 'required|string']);

        return response()->json($this->service->historicoPorNi($request->string('ni')));
    }

    public function anexo(Request $request, Cotacao $cotacao): RedirectResponse
    {
        $this->authorize('editar', 'cotacao');
        $request->validate(['arquivo' => 'required|file|max:20480']);

        $this->service->adicionarAnexo($cotacao, $request->file('arquivo'), $request->user()->id);

        return back()->with('success', 'Anexo enviado.');
    }

    /** Serve o anexo inline (preview/abrir em nova aba). */
    public function verAnexo(Cotacao $cotacao, CotacaoAnexo $anexo): StreamedResponse
    {
        $this->authorize('ver', 'cotacao');
        abort_unless($anexo->cotacao_id === $cotacao->id, 404);
        abort_unless(Storage::exists($anexo->path), 404);

        return Storage::response($anexo->path, $anexo->nome_arquivo);
    }

    public function removeAnexo(Cotacao $cotacao, CotacaoAnexo $anexo): RedirectResponse
    {
        $this->authorize('editar', 'cotacao');
        abort_unless($anexo->cotacao_id === $cotacao->id, 404);

        $this->service->removerAnexo($anexo);

        return back()->with('success', 'Anexo removido.');
    }

    private function formData(Cotacao $cotacao): array
    {
        return [
            'cotacao' => $cotacao,
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
            'escopos' => Escopo::orderBy('descricao')->pluck('descricao', 'id'),
            'unidades' => UnidadeMedida::orderBy('sigla')->pluck('sigla', 'id'),
        ];
    }

    /** @return array{0: array, 1: array} dados de cabecalho e itens */
    private function validar(Request $request): array
    {
        $validated = $request->validate([
            'numero' => 'nullable|string|max:100',
            'numero_cliente' => 'nullable|string|max:100',
            'cliente_id' => 'nullable|exists:clientes,id',
            'escopo_id' => 'nullable|exists:escopos,id',
            'data_cotacao' => 'nullable|date',
            'prazo_resposta' => 'nullable|date',
            'nf_cliente' => 'nullable|string|max:100',
            'observacoes' => 'nullable|string',
            'itens' => 'array',
            'itens.*.descricao' => 'nullable|string',
            'itens.*.quantidade' => 'nullable|numeric|min:0',
        ]);

        $itens = $validated['itens'] ?? [];
        unset($validated['itens']);

        return [$validated, $itens];
    }
}
