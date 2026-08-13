<?php

namespace App\Services;

use App\Events\ProcessoAlterado;
use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Liberacao;
use App\Models\LiberacaoItem;
use App\Models\Output;
use App\Support\MapaAlteracoes;
use App\Support\RotulosDeAlteracao;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;

/**
 * Motor de alteracoes pos-liberacao.
 *
 * MARCO: a altura de corte e a geracao do ULTIMO PDF do PI (o maior
 * `outputs.gerado_em` da demanda). Tudo que a auditoria registrou depois disso
 * conta como alteracao de um processo ja liberado. Antes do primeiro PDF nada e
 * marcado — o processo ainda nao foi entregue a ninguem — e gerar um PDF novo zera
 * a marcacao, porque o PDF novo passa a ser a versao vigente.
 *
 * O historico ("valor anterior -> valor novo, data, usuario") sai inteiro da tabela
 * `audits` (owen-it/laravel-auditing). Nao existe tabela paralela de historico.
 */
class AlteracaoService
{
    /**
     * Origem da demanda: modelo de referencia, modelo do item e a coluna que liga
     * o item a referencia.
     *
     * @var array<string, array{0: class-string, 1: class-string, 2: string}>
     */
    private const ORIGENS = [
        'liberacao' => [Liberacao::class, LiberacaoItem::class, 'liberacao_id'],
        'cotacao' => [Cotacao::class, CotacaoItem::class, 'cotacao_id'],
    ];

    /** Colunas tecnicas que nunca representam mudanca de processo. */
    private const CAMPOS_IGNORADOS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Movimento de fluxo de trabalho nao e mudanca de conteudo: finalizar um item
     * logo depois de gerar o PDF marcaria o processo inteiro sem nada ter mudado.
     *
     * @var array<class-string, array<int, string>>
     */
    private const CAMPOS_IGNORADOS_POR_MODELO = [
        EngenhariaHeader::class => ['status_id'],
        // numero_linha muda em bloco na renumeracao (copia de estrutura); o que
        // importa nesse caso sao as linhas criadas/excluidas, detectadas a parte.
        EngenhariaLinha::class => ['numero_linha', 'status'],
    ];

    /** Rotulo de cada evento da auditoria no historico. */
    private const EVENTOS = [
        'created' => 'Criado',
        'deleted' => 'Excluído',
        'restored' => 'Restaurado',
    ];

    /** Eventos que valem por si so, sem precisar de campo alterado. */
    private const EVENTOS_ESTRUTURAIS = ['created', 'deleted', 'restored'];

    // ---- Marcacao (listagens) ---------------------------------------------

    /**
     * Quais demandas da lista tem alteracao depois do ultimo PDF. Resolve em poucas
     * consultas: a listagem nao pode disparar uma bateria de queries por linha.
     *
     * @param  Collection<int, Demanda>  $demandas
     * @return array<int, bool> demanda_id => alterada
     */
    public function marcadas(Collection $demandas): array
    {
        $ids = $demandas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $marcadas = array_fill_keys($ids, false);

        $marcos = $this->marcos($ids);

        // Demanda sem PDF nunca e marcada: o processo ainda nao foi liberado.
        if ($marcos === []) {
            return $marcadas;
        }

        $registros = $this->registros($demandas->whereIn('id', array_keys($marcos)));

        foreach ($this->auditsPosMarco($registros, $marcos) as $audit) {
            $chave = $this->chaveDoAudit($audit);
            $marcadas[$registros[$chave]['demanda_id']] = true;
        }

        return $marcadas;
    }

    public function houveAlteracao(Demanda $demanda): bool
    {
        return $this->marcadas(collect([$demanda]))[$demanda->id] ?? false;
    }

    // ---- Destaque no preview do PI ----------------------------------------

    /**
     * Mapa consultado pelo preview do PI para pintar de vermelho o que mudou depois
     * do ultimo PDF.
     */
    public function mapa(Demanda $demanda): MapaAlteracoes
    {
        ['marco' => $marco, 'registros' => $registros, 'audits' => $audits] = $this->analisar($demanda);

        if ($audits->isEmpty()) {
            return MapaAlteracoes::vazio();
        }

        $rotulos = new RotulosDeAlteracao;
        $campos = [];
        $criados = [];
        $excluidos = [];
        $rotulosExcluidos = [];

        foreach ($audits as $audit) {
            $chave = $this->chaveDoAudit($audit);
            $modelo = $registros[$chave]['modelo'] ?? null;

            if ($audit->event === 'deleted') {
                $excluidos[] = $chave;
                $rotulosExcluidos[] = $modelo ? RotulosDeAlteracao::registro($modelo) : $chave;

                continue;
            }

            if (in_array($audit->event, self::EVENTOS_ESTRUTURAIS, true)) {
                $criados[] = $chave;

                continue;
            }

            // Registro que nasceu depois do PDF ja e "novo" por inteiro: destacar
            // campo a campo dele mostraria um "antes" que nunca foi liberado.
            if (in_array($chave, $criados, true)) {
                continue;
            }

            foreach ($this->camposMudados($audit) as $campo => $valores) {
                // O primeiro registro guarda o valor anterior ao PDF; as edicoes
                // seguintes so empurram o valor novo.
                $campos[$chave][$campo]['de'] ??= $rotulos->valor($audit->auditable_type, $campo, $valores['de']);
                $campos[$chave][$campo]['para'] = $rotulos->valor($audit->auditable_type, $campo, $valores['para']);
            }
        }

        return new MapaAlteracoes(
            campos: $campos,
            criados: array_values(array_diff(array_unique($criados), $excluidos)),
            excluidos: array_values(array_unique($rotulosExcluidos)),
            marco: $marco,
            total: $audits->count(),
        );
    }

    // ---- Historico do que mudou --------------------------------------------

    /**
     * Lista cronologica "registro, campo, valor anterior, valor novo, data, usuario".
     *
     * @return array{marco: ?CarbonInterface, total: int, eventos: array<int, array<string, mixed>>}
     */
    public function historico(Demanda $demanda): array
    {
        ['marco' => $marco, 'registros' => $registros, 'audits' => $audits] = $this->analisar($demanda);
        $rotulos = new RotulosDeAlteracao;
        $eventos = [];

        foreach ($audits as $audit) {
            $modelo = $registros[$this->chaveDoAudit($audit)]['modelo'] ?? null;
            $base = [
                'registro' => $modelo ? RotulosDeAlteracao::registro($modelo) : class_basename($audit->auditable_type),
                'data' => $audit->created_at,
                'usuario' => $audit->user?->name,
            ];

            if (in_array($audit->event, self::EVENTOS_ESTRUTURAIS, true)) {
                $eventos[] = $base + [
                    'evento' => self::EVENTOS[$audit->event] ?? $audit->event,
                    'campo' => null,
                    'de' => null,
                    'para' => null,
                ];

                continue;
            }

            foreach ($this->camposMudados($audit) as $campo => $valores) {
                $eventos[] = $base + [
                    'evento' => 'Alterado',
                    'campo' => RotulosDeAlteracao::campo($campo),
                    'de' => $rotulos->valor($audit->auditable_type, $campo, $valores['de']),
                    'para' => $rotulos->valor($audit->auditable_type, $campo, $valores['para']),
                ];
            }
        }

        return ['marco' => $marco, 'total' => count($eventos), 'eventos' => $eventos];
    }

    // ---- Aviso a producao e compras ----------------------------------------

    /**
     * Avisa que um processo ja liberado mudou. Silencioso enquanto nao existir PDF:
     * antes da primeira liberacao ninguem tem folha desatualizada na mao.
     */
    public function avisar(?Demanda $demanda): void
    {
        if ($demanda === null || ! $this->houveAlteracao($demanda)) {
            return;
        }

        $this->emitir(new ProcessoAlterado($demanda, $this->numeroDoProcesso($demanda)));
    }

    public function numeroDoProcesso(Demanda $demanda): string
    {
        return $demanda->headers()->value('numero_referencia') ?? ('Demanda #'.$demanda->id);
    }

    // ---- Internos ----------------------------------------------------------

    /**
     * @return array{marco: ?CarbonInterface, registros: array<string, array{demanda_id: int, modelo: Model}>, audits: Collection<int, Audit>}
     */
    private function analisar(Demanda $demanda): array
    {
        $marcos = $this->marcos([$demanda->id]);
        // completos=true: o historico precisa do registro inteiro para se rotular.
        $registros = $marcos === [] ? [] : $this->registros(collect([$demanda]), true);

        return [
            'marco' => $marcos[$demanda->id] ?? null,
            'registros' => $registros,
            'audits' => $this->auditsPosMarco($registros, $marcos),
        ];
    }

    /**
     * Data do ultimo PDF de cada demanda.
     *
     * @param  array<int, int>  $demandaIds
     * @return array<int, CarbonInterface>
     */
    private function marcos(array $demandaIds): array
    {
        if ($demandaIds === []) {
            return [];
        }

        return Output::query()
            ->whereIn('demanda_id', $demandaIds)
            ->selectRaw('demanda_id, MAX(gerado_em) as marco')
            ->groupBy('demanda_id')
            ->pluck('marco', 'demanda_id')
            ->filter()
            ->map(fn ($marco) => Carbon::parse($marco))
            ->all();
    }

    /**
     * Registros que compoem o processo das demandas: o item de engenharia, suas
     * linhas de detalhamento e o PI/cotacao de origem com seus itens. Linhas e itens
     * ja excluidos entram tambem — remover uma linha depois do PDF e alteracao tao
     * grave quanto editar uma.
     *
     * @param  Collection<int, Demanda>  $demandas
     * @param  bool  $completos  carrega o registro inteiro (para rotular) ou so as chaves
     * @return array<string, array{demanda_id: int, modelo: Model}>
     */
    private function registros(Collection $demandas, bool $completos = false): array
    {
        $demandaIds = $demandas->pluck('id')->all();
        $mapa = [];

        if ($demandaIds === []) {
            return $mapa;
        }

        $headers = EngenhariaHeader::withTrashed()
            ->whereIn('demanda_id', $demandaIds)
            ->get($completos ? ['*'] : ['id', 'demanda_id']);

        foreach ($headers as $header) {
            $mapa[MapaAlteracoes::chave($header)] = ['demanda_id' => (int) $header->demanda_id, 'modelo' => $header];
        }

        $demandaDoHeader = $headers->pluck('demanda_id', 'id');
        $linhas = EngenhariaLinha::withTrashed()
            ->whereIn('header_id', $headers->pluck('id')->all())
            ->get($completos ? ['*'] : ['id', 'header_id']);

        foreach ($linhas as $linha) {
            $mapa[MapaAlteracoes::chave($linha)] = [
                'demanda_id' => (int) $demandaDoHeader[$linha->header_id],
                'modelo' => $linha,
            ];
        }

        foreach (self::ORIGENS as $tipo => [$classeReferencia, $classeItem, $chaveEstrangeira]) {
            $mapa += $this->registrosDaOrigem(
                $demandas->where('tipo', $tipo)->pluck('id', 'referencia_id'),
                $classeReferencia,
                $classeItem,
                $chaveEstrangeira,
                $completos,
            );
        }

        return $mapa;
    }

    /**
     * @param  Collection<int, int>  $demandaPorReferencia  referencia_id => demanda_id
     * @param  class-string  $classeReferencia
     * @param  class-string  $classeItem
     * @return array<string, array{demanda_id: int, modelo: Model}>
     */
    private function registrosDaOrigem(
        Collection $demandaPorReferencia,
        string $classeReferencia,
        string $classeItem,
        string $chaveEstrangeira,
        bool $completos,
    ): array {
        $mapa = [];

        if ($demandaPorReferencia->isEmpty()) {
            return $mapa;
        }

        $referencias = $classeReferencia::withTrashed()
            ->whereIn('id', $demandaPorReferencia->keys()->all())
            ->get($completos ? ['*'] : ['id']);

        foreach ($referencias as $referencia) {
            $mapa[MapaAlteracoes::chave($referencia)] = [
                'demanda_id' => (int) $demandaPorReferencia[$referencia->id],
                'modelo' => $referencia,
            ];
        }

        $itens = $classeItem::withTrashed()
            ->whereIn($chaveEstrangeira, $referencias->pluck('id')->all())
            ->get($completos ? ['*'] : ['id', $chaveEstrangeira]);

        foreach ($itens as $item) {
            $mapa[MapaAlteracoes::chave($item)] = [
                'demanda_id' => (int) $demandaPorReferencia[$item->{$chaveEstrangeira}],
                'modelo' => $item,
            ];
        }

        return $mapa;
    }

    /**
     * Eventos de auditoria posteriores ao marco de cada demanda, ja sem o ruido de
     * fluxo de trabalho.
     *
     * @param  array<string, array{demanda_id: int, modelo: Model}>  $registros
     * @param  array<int, CarbonInterface>  $marcos
     * @return Collection<int, Audit>
     */
    private function auditsPosMarco(array $registros, array $marcos): Collection
    {
        $porTipo = [];

        foreach ($registros as $dados) {
            if (isset($marcos[$dados['demanda_id']])) {
                $porTipo[$dados['modelo']::class][] = $dados['modelo']->getKey();
            }
        }

        if ($porTipo === []) {
            return collect();
        }

        // Corta pelo marco mais antigo no banco; o marco exato de cada demanda e
        // aplicado depois, ja com os registros em maos.
        $maisAntigo = array_reduce(
            $marcos,
            fn (?CarbonInterface $menor, CarbonInterface $marco) => $menor === null || $marco->lt($menor) ? $marco : $menor
        );

        return Audit::query()
            ->with('user')
            ->where('created_at', '>', $maisAntigo)
            ->where(function (Builder $consulta) use ($porTipo) {
                foreach ($porTipo as $tipo => $ids) {
                    $consulta->orWhere(fn (Builder $w) => $w->where('auditable_type', $tipo)->whereIn('auditable_id', $ids));
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Audit $audit) => $this->relevante($audit, $registros, $marcos))
            ->values();
    }

    /**
     * @param  array<string, array{demanda_id: int, modelo: Model}>  $registros
     * @param  array<int, CarbonInterface>  $marcos
     */
    private function relevante(Audit $audit, array $registros, array $marcos): bool
    {
        $demandaId = $registros[$this->chaveDoAudit($audit)]['demanda_id'] ?? null;

        if ($demandaId === null || ! $audit->created_at?->gt($marcos[$demandaId])) {
            return false;
        }

        return in_array($audit->event, self::EVENTOS_ESTRUTURAIS, true)
            ? true
            : $this->camposMudados($audit) !== [];
    }

    /**
     * Campos que realmente mudaram no evento, sem as colunas ignoradas.
     *
     * @return array<string, array{de: mixed, para: mixed}>
     */
    private function camposMudados(Audit $audit): array
    {
        $ignorados = array_merge(
            self::CAMPOS_IGNORADOS,
            self::CAMPOS_IGNORADOS_POR_MODELO[$audit->auditable_type] ?? []
        );

        $antigos = $audit->old_values ?? [];
        $mudancas = [];

        foreach ($audit->new_values ?? [] as $campo => $valor) {
            $anterior = $antigos[$campo] ?? null;

            // Gravacao sem mudanca real (ex.: mesmo valor reenviado pelo formulario)
            // nao pode virar aviso de alteracao.
            if (in_array($campo, $ignorados, true) || (string) $anterior === (string) $valor) {
                continue;
            }

            $mudancas[$campo] = ['de' => $anterior, 'para' => $valor];
        }

        return $mudancas;
    }

    private function chaveDoAudit(Audit $audit): string
    {
        return $audit->auditable_type.'#'.$audit->auditable_id;
    }

    /** Transmite o evento de forma resiliente: falha de broadcast (Reverb fora) nao quebra a operacao. */
    private function emitir(object $evento): void
    {
        try {
            event($evento);
        } catch (\Throwable $e) {
            Log::warning('Broadcast falhou: '.$e->getMessage());
        }
    }
}
