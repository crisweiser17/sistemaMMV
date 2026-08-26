<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ClienteUnidade;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Motor das unidades de cliente: consulta das unidades ativas (dropdowns),
 * sincronizacao do repeater de unidades dentro do cadastro do cliente e
 * conversao dos campos legados (clientes.unidade e clientes.codigo_pa)
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

    /**
     * Regras das linhas do repeater de unidades (tela de cadastro do cliente).
     * TODA chave que a sincronizacao grava precisa de regra aqui: $request->validate()
     * devolve somente as chaves com regra declarada e descarta o resto em silencio —
     * sem 'unidades.*.id' a sincronizacao recriaria a unidade e quebraria o vinculo
     * com PIs, cotacoes e headers de engenharia ja existentes.
     *
     * @return array<string, mixed>
     */
    public static function regrasDoRepeater(?int $clienteId): array
    {
        // No cadastro novo nao ha unidade para referenciar; ids sao ignorados na sincronizacao.
        $regraId = $clienteId
            ? ['nullable', 'integer', Rule::exists('cliente_unidades', 'id')->where('cliente_id', $clienteId)->whereNull('deleted_at')]
            : ['nullable', 'integer'];

        return [
            'unidades' => ['array'],
            'unidades.*.id' => $regraId,
            'unidades.*.nome' => ['required', 'string', 'max:255'],
            'unidades.*.codigo' => ['nullable', 'string', 'max:50'],
            'unidades.*.ativo' => ['boolean'],
        ];
    }

    /**
     * Quantos registros de negocio apontam para esta unidade. A FK e nullOnDelete:
     * remover a unidade apagaria o vinculo do pedido sem aviso nenhum.
     */
    public function contarVinculos(ClienteUnidade $unidade): int
    {
        return collect(self::TABELAS_VINCULADAS)
            ->sum(fn (string $tabela) => DB::table($tabela)->where('unidade_id', $unidade->id)->count());
    }

    /**
     * Aplica ao cliente a lista de unidades vinda do formulario, num submit so:
     * linha com id existente e atualizada, linha sem id vira unidade nova e
     * unidade que sumiu da lista e removida — desde que ninguem dependa dela.
     *
     * @param  array<int, array<string, mixed>>  $linhas
     *
     * @throws ValidationException quando a remocao apagaria o vinculo de um pedido
     */
    public function sincronizar(Cliente $cliente, array $linhas): void
    {
        $atuais = $cliente->unidades()->get()->keyBy('id');

        $idsMantidos = collect($linhas)
            ->map(fn (array $linha) => (int) ($linha['id'] ?? 0))
            ->filter(fn (int $id) => $atuais->has($id))
            ->all();

        $remover = $atuais->except($idsMantidos);

        // Valida a remocao inteira ANTES de gravar qualquer coisa: ou o submit passa todo, ou nada muda.
        foreach ($remover as $unidade) {
            $vinculos = $this->contarVinculos($unidade);

            if ($vinculos > 0) {
                throw ValidationException::withMessages([
                    'unidades' => sprintf(
                        'A unidade "%s" não pode ser removida: %d registro(s) entre PIs, cotações e engenharia dependem dela. '
                        .'Desmarque "Ativo" para tirá-la dos novos pedidos sem perder o histórico.',
                        $unidade->nome,
                        $vinculos,
                    ),
                ]);
            }
        }

        DB::transaction(function () use ($cliente, $linhas, $atuais, $remover) {
            foreach ($remover as $unidade) {
                $unidade->delete();
            }

            foreach ($linhas as $linha) {
                $dados = self::normalizarLinha($linha);
                $id = (int) ($linha['id'] ?? 0);

                if ($atuais->has($id)) {
                    $atuais->get($id)->update($dados);

                    continue;
                }

                $cliente->unidades()->create($dados);
            }
        });
    }

    /**
     * Linha do formulario -> atributos gravaveis. Codigo em branco vira null
     * (nao existe codigo "vazio"; ou tem, ou nao tem).
     *
     * @param  array<string, mixed>  $linha
     * @return array<string, mixed>
     */
    private static function normalizarLinha(array $linha): array
    {
        $codigo = trim((string) ($linha['codigo'] ?? ''));

        return [
            'nome' => trim((string) ($linha['nome'] ?? '')),
            'codigo' => $codigo === '' ? null : $codigo,
            'ativo' => filter_var($linha['ativo'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Converte o campo legado clientes.codigo_pa: o codigo passa a ser POR UNIDADE
     * (Suzano Tres Lagoas 10, Suzano Jacarei 25...), entao so ha um destino obvio
     * quando o cliente tem exatamente UMA unidade e ela ainda esta sem codigo.
     * Cliente com varias unidades nao e adivinhado — volta na lista de pendentes
     * para o operador preencher na tela.
     *
     * @return array{movidos: int, pendentes: array<int, string>}
     */
    public function moverCodigoPaParaUnidades(): array
    {
        if (! Schema::hasColumn('clientes', 'codigo_pa')) {
            return ['movidos' => 0, 'pendentes' => []];
        }

        $movidos = 0;
        $pendentes = [];

        $clientes = DB::table('clientes')
            ->whereNotNull('codigo_pa')
            ->where('codigo_pa', '<>', '')
            ->get(['id', 'nome', 'codigo_pa']);

        foreach ($clientes as $cliente) {
            $unidades = ClienteUnidade::where('cliente_id', $cliente->id)->get();
            $unica = $unidades->count() === 1 ? $unidades->first() : null;

            if (! $unica) {
                $pendentes[] = sprintf(
                    'Cliente "%s" (codigo_pa %s): %d unidade(s) — codigo por unidade precisa ser preenchido a mao.',
                    $cliente->nome, $cliente->codigo_pa, $unidades->count(),
                );

                continue;
            }

            if (trim((string) $unica->codigo) !== '') {
                $pendentes[] = sprintf(
                    'Cliente "%s" (codigo_pa %s): unidade "%s" ja tem o codigo %s — codigo_pa descartado.',
                    $cliente->nome, $cliente->codigo_pa, $unica->nome, $unica->codigo,
                );

                continue;
            }

            $unica->update(['codigo' => trim((string) $cliente->codigo_pa)]);
            $movidos++;
        }

        return ['movidos' => $movidos, 'pendentes' => $pendentes];
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
