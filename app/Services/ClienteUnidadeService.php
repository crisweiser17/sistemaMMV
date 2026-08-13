<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ClienteUnidade;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Motor das unidades de cliente: consulta das unidades ativas (dropdowns) e
 * conversao do campo legado clientes.unidade (texto, uma unidade por cliente)
 * para a tabela cliente_unidades. Sem dependencia de HTTP.
 */
class ClienteUnidadeService
{
    /** Tabelas com FK unidade_id que herdam a unidade do cliente. */
    public const TABELAS_VINCULADAS = ['liberacoes', 'cotacoes', 'engenharia_headers'];

    /**
     * Regra de validacao do campo unidade_id: opcional, mas quando informado
     * precisa ser uma unidade viva DO cliente escolhido (evita vinculo cruzado).
     *
     * @return array<int, mixed>
     */
    public static function regraDeValidacao(?int $clienteId): array
    {
        return [
            'nullable',
            Rule::exists('cliente_unidades', 'id')
                ->where('cliente_id', $clienteId)
                ->whereNull('deleted_at'),
        ];
    }

    /** Unidades ativas de um cliente, em ordem alfabetica. */
    public function ativasDoCliente(?int $clienteId): Collection
    {
        if (! $clienteId) {
            return new Collection;
        }

        return ClienteUnidade::query()
            ->where('cliente_id', $clienteId)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'codigo', 'cliente_id']);
    }

    /**
     * Converte o campo legado: cria uma ClienteUnidade por cliente com unidade
     * preenchida e aponta os registros daquele cliente que ainda estao sem unidade.
     * Idempotente — rodar de novo nao duplica nem sobrescreve vinculos existentes.
     *
     * @return int quantidade de unidades criadas
     */
    public function migrarLegado(): int
    {
        if (! Schema::hasColumn('clientes', 'unidade')) {
            return 0;
        }

        $criadas = 0;

        $clientes = Cliente::withTrashed()
            ->whereNotNull('unidade')
            ->where('unidade', '<>', '')
            ->get();

        foreach ($clientes as $cliente) {
            $unidade = ClienteUnidade::firstOrCreate(
                ['cliente_id' => $cliente->id, 'nome' => trim((string) $cliente->unidade)],
                ['ativo' => true],
            );

            if ($unidade->wasRecentlyCreated) {
                $criadas++;
            }

            foreach (self::TABELAS_VINCULADAS as $tabela) {
                DB::table($tabela)
                    ->where('cliente_id', $cliente->id)
                    ->whereNull('unidade_id')
                    ->update(['unidade_id' => $unidade->id]);
            }
        }

        return $criadas;
    }
}
