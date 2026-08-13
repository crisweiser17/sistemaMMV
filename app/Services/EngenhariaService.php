<?php

namespace App\Services;

use App\Events\EngenhariaAtualizada;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\StatusEngenharia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Motor de Engenharia: linhas de detalhamento, dependencias entre linhas,
 * finalizacao do item e propagacao do status para a demanda.
 */
class EngenhariaService
{
    /** Acrescenta a estrutura copiada ao final das linhas ja existentes. */
    public const MODO_ACRESCENTAR = 'acrescentar';

    /** Descarta as linhas atuais do item antes de copiar. */
    public const MODO_SUBSTITUIR = 'substituir';

    private const STATUS_A_INICIAR = 'A iniciar';

    private const STATUS_EM_ANDAMENTO = 'Em andamento';

    private const STATUS_FINALIZADO = 'Finalizado';

    /**
     * Campos de negocio que a copia de estrutura leva junto. Ficam de fora de proposito:
     * arquivo_path (o desenho e do item de origem) e status (a copia nasce em branco).
     */
    private const CAMPOS_COPIAVEIS = [
        'descricao', 'cod_mmv', 'local_referencia', 'escopo_id', 'tipo_componente',
        'categoria_componente_id', 'tipo_componente_id', 'material_id', 'mao_de_obra',
        'quantidade', 'unidade_id', 'observacao', 'fase', 'duracao_dias',
    ];

    /** Teto de resultados da busca de estruturas: a lista e para escolher, nao para navegar. */
    private const LIMITE_ESTRUTURAS = 20;

    public function __construct(
        private DemandaService $demandas,
        private AnexoService $anexos,
        private AlteracaoService $alteracoes,
    ) {}

    public function adicionarLinha(EngenhariaHeader $header, array $dados): EngenhariaLinha
    {
        $dados['numero_linha'] = $dados['numero_linha'] ?? (((int) $header->linhas()->max('numero_linha')) + 1);
        $linha = $header->linhas()->create($dados);

        $this->garantirEmAndamento($header);
        $this->sincronizar($header->fresh());

        return $linha;
    }

    public function atualizarLinha(EngenhariaLinha $linha, array $dados): EngenhariaLinha
    {
        $linha->update($dados);
        $this->sincronizar($linha->header);

        return $linha->fresh();
    }

    public function removerLinha(EngenhariaLinha $linha): void
    {
        $header = $linha->header;
        $linha->delete();
        $this->sincronizar($header->fresh());
    }

    /** Registra dependencia M:N entre linhas (linha depende de outra). */
    public function adicionarDependencia(EngenhariaLinha $linha, int $dependeDeLinhaId): void
    {
        if ($dependeDeLinhaId !== $linha->id) {
            $linha->dependencias()->syncWithoutDetaching([$dependeDeLinhaId]);
            $this->sincronizar($linha->header);
        }
    }

    /** Define dependencias a partir de uma lista (ex.: "2,3" -> numeros de linha do mesmo header). */
    public function definirDependenciasPorNumeros(EngenhariaLinha $linha, array $numeros): void
    {
        $ids = $linha->header->linhas()
            ->whereIn('numero_linha', $numeros)
            ->where('id', '!=', $linha->id)
            ->pluck('id')->all();

        $linha->dependencias()->sync($ids);
        $this->sincronizar($linha->header);
    }

    /**
     * Itens ja concluidos que servem de molde para copiar a estrutura.
     * Busca por codigo MMV, NI e descricao do item de origem, alem do nome do item
     * e do numero do PI/cotacao.
     *
     * @return array<int, array<string, mixed>>
     */
    public function estruturasCopiaveis(EngenhariaHeader $destino, string $termo = ''): array
    {
        $termo = trim($termo);

        return $this->elegiveisParaCopia($destino)
            ->with(['cliente', 'unidade', 'itemLiberacao', 'itemCotacao'])
            ->withCount('linhas')
            ->when($termo !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($termo) {
                $like = '%'.$termo.'%';
                $w->where('nome_item', 'like', $like)
                    ->orWhere('numero_referencia', 'like', $like)
                    ->orWhereHas('itemLiberacao', fn (Builder $i) => $this->filtrarItemOrigem($i, $like))
                    ->orWhereHas('itemCotacao', fn (Builder $i) => $this->filtrarItemOrigem($i, $like));
            }))
            ->orderByDesc('data_alocacao')
            ->orderByDesc('id')
            ->limit(self::LIMITE_ESTRUTURAS)
            ->get()
            ->map(function (EngenhariaHeader $header) {
                $item = $header->dadosItemOrigem();

                return [
                    'id' => $header->id,
                    'numero_referencia' => $header->numero_referencia,
                    'nome_item' => $header->nome_item,
                    'cod_mmv' => $item['cod_mmv'],
                    'ni' => $item['ni'],
                    // Rotulo "Cliente - Unidade" vem do acessor: a tela nao concatena nada.
                    'cliente' => $header->cliente_com_unidade,
                    'data' => $header->data_alocacao?->format('d/m/Y'),
                    'linhas' => $header->linhas_count,
                ];
            })
            ->all();
    }

    /**
     * Copia as linhas de detalhamento (e as dependencias entre elas) de um item ja
     * concluido para outro item. Nada do header de origem vem junto.
     *
     * @return int quantidade de linhas copiadas
     */
    public function copiarEstrutura(
        EngenhariaHeader $destino,
        EngenhariaHeader $origem,
        string $modo = self::MODO_ACRESCENTAR,
    ): int {
        if (! in_array($modo, [self::MODO_ACRESCENTAR, self::MODO_SUBSTITUIR], true)) {
            throw ValidationException::withMessages(['modo' => 'Modo de copia invalido.']);
        }

        // Revalida a elegibilidade no servidor: a lista do modal e so uma conveniencia.
        if (! $this->elegiveisParaCopia($destino)->whereKey($origem->id)->exists()) {
            throw ValidationException::withMessages([
                'origem_id' => 'O item de origem precisa ser outro item, ja finalizado e com linhas de detalhamento.',
            ]);
        }

        return DB::transaction(function () use ($destino, $origem, $modo) {
            $linhasOrigem = $origem->linhas()->with('dependencias')->get();

            if ($modo === self::MODO_SUBSTITUIR) {
                $destino->linhas()->get()->each(fn (EngenhariaLinha $linha) => $linha->delete());
            }

            // Renumera o que sobrou antes de acrescentar: buracos deixados por exclusoes
            // anteriores nao podem sobreviver a copia.
            $proximo = $this->renumerarLinhas($destino);

            // De->para entre a linha de origem e a copia; sem isso as dependencias
            // do item novo continuariam apontando para as linhas do item antigo.
            $copias = [];
            foreach ($linhasOrigem as $linha) {
                $copias[$linha->id] = $destino->linhas()->create(
                    array_merge($this->camposCopiaveis($linha), ['numero_linha' => $proximo++])
                );
            }

            foreach ($linhasOrigem as $linha) {
                $ids = $linha->dependencias
                    ->map(fn (EngenhariaLinha $dependencia) => $copias[$dependencia->id]->id ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if ($ids !== []) {
                    $copias[$linha->id]->dependencias()->sync($ids);
                }
            }

            $this->garantirEmAndamento($destino);
            // Copia feita depois do PDF entra como alteracao: sao linhas novas no
            // processo que producao e compras ja receberam.
            $this->sincronizar($destino->fresh());

            return count($copias);
        });
    }

    /** Finaliza o item; recalcula o status da demanda (todos finalizados => demanda Finalizada). */
    public function finalizar(EngenhariaHeader $header): EngenhariaHeader
    {
        return DB::transaction(function () use ($header) {
            $header->update(['status_id' => $this->statusId(self::STATUS_FINALIZADO)]);
            $this->emitir(new EngenhariaAtualizada($header->fresh()));

            $this->demandas->recalcularStatus($header->demanda);

            return $header->fresh();
        });
    }

    public function anexarArquivoLinha(EngenhariaLinha $linha, \Illuminate\Http\UploadedFile $arquivo): EngenhariaLinha
    {
        // Substitui o anexo anterior: sem isso o arquivo antigo fica orfao no disco.
        $anterior = $linha->arquivo_path;
        $path = $this->anexos->guardar($arquivo, "engenharia/{$linha->header_id}/{$linha->id}");
        $linha->update(['arquivo_path' => $path]);
        $this->anexos->apagar($anterior);

        $this->sincronizar($linha->header);

        return $linha->fresh();
    }

    public function removerArquivoLinha(EngenhariaLinha $linha): EngenhariaLinha
    {
        if ($linha->arquivo_path) {
            $this->anexos->apagar($linha->arquivo_path);
            $linha->update(['arquivo_path' => null]);
            $this->sincronizar($linha->header);
        }

        return $linha->fresh();
    }

    /**
     * Itens que podem servir de origem para uma copia: outro item, com o header ja
     * finalizado e com pelo menos uma linha de detalhamento.
     */
    private function elegiveisParaCopia(EngenhariaHeader $destino): Builder
    {
        $finalizadoId = $this->statusId(self::STATUS_FINALIZADO);

        // Sem o status cadastrado nao existe item concluido a oferecer.
        if ($finalizadoId === null) {
            return EngenhariaHeader::query()->whereRaw('1 = 0');
        }

        return EngenhariaHeader::query()
            ->whereKeyNot($destino->id)
            ->where('status_id', $finalizadoId)
            ->whereHas('linhas');
    }

    /** Trecho de busca comum aos dois tipos de item de origem (PI e cotacao). */
    private function filtrarItemOrigem(Builder $query, string $like): Builder
    {
        return $query->where(fn (Builder $w) => $w->where('cod_mmv', 'like', $like)
            ->orWhere('ni', 'like', $like)
            ->orWhere('descricao', 'like', $like));
    }

    /** Valores de negocio da linha de origem, sem os campos que nao acompanham a copia. */
    private function camposCopiaveis(EngenhariaLinha $linha): array
    {
        return Arr::only($linha->getAttributes(), self::CAMPOS_COPIAVEIS);
    }

    /** Reescreve a numeracao do item como 1..N e devolve o proximo numero livre. */
    private function renumerarLinhas(EngenhariaHeader $header): int
    {
        $numero = 1;

        foreach ($header->linhas()->get() as $linha) {
            if ((int) $linha->numero_linha !== $numero) {
                $linha->update(['numero_linha' => $numero]);
            }
            $numero++;
        }

        return $numero;
    }

    /** Item que ganha detalhamento sai de "A iniciar" (ou de status vazio) para "Em andamento". */
    private function garantirEmAndamento(EngenhariaHeader $header): void
    {
        if (! $header->status || $header->status->nome === self::STATUS_A_INICIAR) {
            $header->update(['status_id' => $this->statusId(self::STATUS_EM_ANDAMENTO)]);
        }
    }

    private function statusId(string $nome): ?int
    {
        return StatusEngenharia::where('nome', $nome)->value('id');
    }

    /**
     * Reflete a mudanca do item nas telas abertas e, quando o processo ja foi
     * liberado (ja tem PDF do PI), avisa producao e compras que ele mudou.
     */
    private function sincronizar(?EngenhariaHeader $header): void
    {
        if (! $header) {
            return;
        }

        $this->emitir(new EngenhariaAtualizada($header));
        $this->alteracoes->avisar($header->demanda);
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
