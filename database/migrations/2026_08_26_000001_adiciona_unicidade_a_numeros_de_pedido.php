<?php

use App\Models\Cotacao;
use App\Models\Liberacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numero de PI e numero de cotacao passam a ser unicos GLOBALMENTE — nao por
     * cliente e nao por ano. Validacao no controller resolve o caso do dia a dia,
     * mas nao segura dois salvamentos simultaneos; a garantia real e este indice.
     *
     * Ordem obrigatoria: resolver as duplicatas ja gravadas ANTES de criar o
     * indice, senao o CREATE INDEX nem sobe numa base que ja repete numero.
     */
    private const ALVOS = [
        [
            'modelo' => Liberacao::class,
            'tabela' => 'liberacoes',
            'coluna' => 'numero_pi',
            'indice' => 'liberacoes_numero_pi_unique',
            'data' => 'data_pedido',
            'rotulo' => 'PI',
        ],
        [
            'modelo' => Cotacao::class,
            'tabela' => 'cotacoes',
            'coluna' => 'numero',
            'indice' => 'cotacoes_numero_unique',
            'data' => 'data_cotacao',
            'rotulo' => 'cotacao',
        ],
    ];

    public function up(): void
    {
        foreach (self::ALVOS as $alvo) {
            $this->resolverDuplicatas($alvo);
            $this->criarIndice($alvo);
        }
    }

    /**
     * So o indice e desfeito. Renumeracao nao volta atras: depois de gravado nao da
     * mais para saber quem era duplicata de quem, e adivinhar corromperia dado bom.
     */
    public function down(): void
    {
        foreach (array_reverse(self::ALVOS) as $alvo) {
            if (! $this->temIndice($alvo['tabela'], $alvo['indice'])) {
                continue;
            }

            Schema::table($alvo['tabela'], fn (Blueprint $tabela) => $tabela->dropIndex($alvo['indice']));
        }
    }

    // ---- Limpeza das duplicatas -------------------------------------------

    /**
     * @param  array<string, string>  $alvo
     */
    private function resolverDuplicatas(array $alvo): void
    {
        $modelo = $alvo['modelo'];

        // Registro em soft delete fica de fora: ele nao ocupa numero (o indice
        // criado abaixo tem o mesmo recorte). Numero vazio tambem fica de fora,
        // porque vazio nunca colide com vazio.
        $repetidos = $modelo::query()
            ->whereNotNull($alvo['coluna'])
            ->where($alvo['coluna'], '<>', '')
            ->groupBy($alvo['coluna'])
            ->havingRaw('count(*) > 1')
            ->pluck($alvo['coluna']);

        foreach ($repetidos as $numero) {
            $this->desempatar($alvo, (string) $numero);
        }
    }

    /**
     * O mais antigo (menor id) fica com o numero original. Cada excedente e vazio
     * (some) ou tem conteudo (ganha sufixo).
     *
     * @param  array<string, string>  $alvo
     */
    private function desempatar(array $alvo, string $numero): void
    {
        $excedentes = $alvo['modelo']::query()
            ->where($alvo['coluna'], $numero)
            ->orderBy('id')
            ->get()
            ->skip(1);

        $sufixo = 2;

        foreach ($excedentes as $registro) {
            if ($this->estaVazio($alvo, $registro)) {
                // Sobra de formulario quebrado: soft delete, reversivel se o
                // operador reclamar depois.
                $registro->delete();

                Log::warning('Unicidade de numero: duplicata vazia arquivada (soft delete).', [
                    'tabela' => $alvo['tabela'],
                    'id' => $registro->getKey(),
                    'numero' => $numero,
                ]);

                continue;
            }

            $sufixo = $this->sufixoLivre($alvo, $numero, $sufixo);
            $novo = $numero.'-'.$sufixo;
            $sufixo++;

            $registro->update([$alvo['coluna'] => $novo]);

            Log::warning('Unicidade de numero: duplicata com conteudo renumerada.', [
                'tabela' => $alvo['tabela'],
                'id' => $registro->getKey(),
                'de' => $numero,
                'para' => $novo,
                'rotulo' => $alvo['rotulo'],
            ]);
        }
    }

    /**
     * Vazio = sem cliente, sem data e sem itens. Nao ha o que preservar num
     * registro assim alem do numero, que e justamente o que esta atrapalhando.
     *
     * @param  array<string, string>  $alvo
     */
    private function estaVazio(array $alvo, Model $registro): bool
    {
        return $registro->cliente_id === null
            && $registro->{$alvo['data']} === null
            && $registro->itens()->count() === 0;
    }

    /**
     * Sufixo previsivel (1167-2, 1167-3, ...), pulando o que ja estiver ocupado —
     * pode existir um "1167-2" legitimo digitado a mao.
     *
     * @param  array<string, string>  $alvo
     */
    private function sufixoLivre(array $alvo, string $base, int $sufixo): int
    {
        while ($alvo['modelo']::query()->where($alvo['coluna'], $base.'-'.$sufixo)->exists()) {
            $sufixo++;
        }

        return $sufixo;
    }

    // ---- Indice ------------------------------------------------------------

    /**
     * NULL nao colide com NULL em indice unico — vale em SQLite, MySQL e Postgres —
     * entao dois registros sem numero continuam convivendo.
     *
     * O recorte "deleted_at is null" existe porque registro arquivado nao pode
     * segurar o numero: SQLite e Postgres fazem isso com indice parcial, MySQL 8
     * chega no mesmo lugar com indice funcional (a expressao vira NULL na linha
     * arquivada, e NULL nao colide).
     *
     * @param  array<string, string>  $alvo
     */
    private function criarIndice(array $alvo): void
    {
        // Idempotente: rodar de novo numa base ja tratada nao faz nada.
        if ($this->temIndice($alvo['tabela'], $alvo['indice'])) {
            return;
        }

        $sql = match (Schema::getConnection()->getDriverName()) {
            'sqlite', 'pgsql' => sprintf(
                'create unique index %s on %s (%s) where deleted_at is null',
                $alvo['indice'], $alvo['tabela'], $alvo['coluna']
            ),
            'mysql' => sprintf(
                'create unique index %s on %s ((case when deleted_at is null then %s end))',
                $alvo['indice'], $alvo['tabela'], $alvo['coluna']
            ),
            // Driver sem indice parcial nem funcional (MariaDB): indice unico simples.
            // Unicidade continua garantida; o unico efeito colateral e um registro
            // arquivado continuar segurando o numero dele.
            default => null,
        };

        if ($sql === null) {
            Schema::table($alvo['tabela'], fn (Blueprint $tabela) => $tabela->unique($alvo['coluna'], $alvo['indice']));

            return;
        }

        DB::statement($sql);
    }

    private function temIndice(string $tabela, string $nome): bool
    {
        foreach (Schema::getIndexes($tabela) as $indice) {
            if ($indice['name'] === $nome) {
                return true;
            }
        }

        return false;
    }
};
