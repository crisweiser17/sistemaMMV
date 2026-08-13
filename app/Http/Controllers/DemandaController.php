<?php

namespace App\Http\Controllers;

use App\Http\Resources\DemandaResource;
use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\StatusEngenharia;
use App\Models\User;
use App\Services\AlteracaoService;
use App\Services\DemandaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemandaController extends Controller
{
    public function __construct(
        private DemandaService $service,
        private AlteracaoService $alteracoes,
    ) {}

    public function index(): View
    {
        return view('demandas.index', [
            'responsaveis' => User::where('ativo', true)->orderBy('name')->pluck('name', 'id'),
            'statuses' => StatusEngenharia::orderBy('ordem')->get(),
            'clientes' => Cliente::orderBy('nome')->pluck('nome', 'id'),
        ]);
    }

    /** Endpoint JSON (motor) consumido pelo grid reativo liveResource. */
    public function data(Request $request): JsonResponse
    {
        $demandas = Demanda::query()
            ->with(['responsavel', 'status', 'headers'])
            ->when($request->filled('responsavel_id'), fn ($q) => $q->where('responsavel_id', $request->integer('responsavel_id')))
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->integer('status_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->latest('data_entrada')
            ->get();

        // Filtros que dependem da referencia (PI/cotacao) sao aplicados depois de resolve-la:
        // numero, cliente e unidade do cliente vivem no PI/cotacao, nao na demanda.
        $busca = trim((string) $request->string('busca'));
        $clienteId = $request->integer('cliente_id');
        $unidadeId = $request->integer('unidade_id');

        // Marcacao "ALTERADO" resolvida em lote para a lista inteira (ver AlteracaoService).
        $marcadas = $this->alteracoes->marcadas($demandas);
        $colecao = $demandas
            ->map(fn (Demanda $d) => (new DemandaResource($d, $marcadas[$d->id] ?? false))->resolve())
            ->all();

        if ($busca !== '') {
            $colecao = array_filter($colecao, fn ($d) => str_contains(mb_strtolower($d['numero_referencia']), mb_strtolower($busca)));
        }
        if ($clienteId) {
            $colecao = array_filter($colecao, fn ($d) => $d['cliente_id'] === $clienteId);
        }
        if ($unidadeId) {
            $colecao = array_filter($colecao, fn ($d) => $d['unidade_id'] === $unidadeId);
        }

        return response()->json(['data' => array_values($colecao)]);
    }

    public function alocar(Request $request, Demanda $demanda): JsonResponse
    {
        $this->authorize('editar', 'demandas');
        $request->validate(['responsavel_id' => 'required|exists:users,id']);

        $this->service->alocar($demanda, $request->integer('responsavel_id'));

        return response()->json(['ok' => true, 'message' => 'Demanda alocada — headers de engenharia gerados.']);
    }

    public function status(Request $request, Demanda $demanda): JsonResponse
    {
        $this->authorize('editar', 'demandas');
        $request->validate(['status_id' => 'required|exists:status_engenharia,id']);

        $this->service->atualizarStatus($demanda, $request->integer('status_id'));

        return response()->json(['ok' => true]);
    }
}
