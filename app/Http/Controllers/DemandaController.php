<?php

namespace App\Http\Controllers;

use App\Http\Resources\DemandaResource;
use App\Models\Demanda;
use App\Models\StatusEngenharia;
use App\Models\User;
use App\Services\DemandaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemandaController extends Controller
{
    public function __construct(private DemandaService $service) {}

    public function index(): View
    {
        return view('demandas.index', [
            'responsaveis' => User::where('ativo', true)->orderBy('name')->pluck('name', 'id'),
            'statuses' => StatusEngenharia::orderBy('ordem')->get(),
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

        // Filtro por numero do PI/cotacao (referencia) feito apos resolver a referencia.
        $busca = trim((string) $request->string('busca'));

        $colecao = DemandaResource::collection($demandas)->resolve();
        if ($busca !== '') {
            $colecao = array_values(array_filter($colecao, fn ($d) => str_contains(mb_strtolower($d['numero_referencia']), mb_strtolower($busca))));
        }

        return response()->json(['data' => $colecao]);
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
