<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaComponente;
use App\Models\Material;
use App\Models\ProcessoFabricacao;
use App\Models\TipoComponente;
use App\Services\ClienteUnidadeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints JSON consumidos pelos dropdowns encadeados (fabrica Alpine dependentSelects).
 * Sao "motores" de apoio: dados sem layout, reutilizaveis por qualquer cliente.
 */
class LookupController extends Controller
{
    // GET /admin/categorias?tipo=materia_prima|servico|comercial
    public function categorias(Request $request): JsonResponse
    {
        $categorias = CategoriaComponente::query()
            ->where('ativo', true)
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->orderBy('nome')
            ->get(['id', 'nome', 'tipo']);

        return response()->json($categorias);
    }

    // GET /admin/tipos?categoria_id=Y
    public function tipos(Request $request): JsonResponse
    {
        $tipos = TipoComponente::query()
            ->where('ativo', true)
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))
            ->orderBy('nome')
            ->get(['id', 'nome', 'categoria_id']);

        return response()->json($tipos);
    }

    // GET /admin/lookup/materiais?tipo_id=Y (prefixo /lookup: 'materiais' tambem e slug de CRUD)
    public function materiais(Request $request): JsonResponse
    {
        $materiais = Material::query()
            // Categoria e tipo alimentam o rotulo completo; sem o eager-load seria 1 query por material.
            ->with('tipo.categoria')
            ->where('ativo', true)
            ->when($request->filled('tipo_id'), fn ($q) => $q->where('tipo_id', $request->integer('tipo_id')))
            ->orderBy('descricao')
            ->get(['id', 'tipo_id', 'descricao', 'dimensoes', 'norma'])
            ->map(fn (Material $m) => [
                'id' => $m->id,
                'tipo_id' => $m->tipo_id,
                'descricao' => $m->descricao,
                'dimensoes' => $m->dimensoes,
                'norma' => $m->norma,
                // Rotulo montado no model (fonte unica): o dropdown mostra a especificacao inteira.
                'especificacao' => $m->especificacao_completa,
            ])
            ->values();

        return response()->json($materiais);
    }

    // GET /admin/lookup/unidades?cliente_id=X — unidades ativas do cliente
    public function unidadesDoCliente(Request $request, ClienteUnidadeService $servico): JsonResponse
    {
        return response()->json($servico->ativasDoCliente($request->integer('cliente_id') ?: null));
    }

    // GET /admin/processos?sub_processo=...
    public function processos(Request $request): JsonResponse
    {
        $processos = ProcessoFabricacao::query()
            ->where('ativo', true)
            ->when($request->filled('sub_processo'), fn ($q) => $q->where('sub_processo', 'like', '%'.$request->string('sub_processo').'%'))
            ->orderBy('macro_processo')
            ->get(['id', 'macro_processo', 'processo', 'sub_processo', 'descricao_servico']);

        return response()->json($processos);
    }
}
