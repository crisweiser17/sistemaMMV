<?php

namespace App\Http\Resources;

use App\Services\GanttService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape JSON da Demanda para o grid reativo (liveResource).
 * Esta Resource e o "contrato" do motor consumido pelo layout.
 */
class DemandaResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     * @param  bool  $alterado  processo mudou depois do ultimo PDF do PI. Vem pronto do
     *                          AlteracaoService::marcadas() porque a deteccao e feita em
     *                          lote para a listagem inteira, nao demanda a demanda.
     */
    public function __construct($resource, private readonly bool $alterado = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $ref = $this->referencia();

        return [
            'id' => $this->id,
            // Marcador "ALTERADO" da listagem + atalho para o historico da mudanca.
            'alterado' => $this->alterado,
            'alteracoes_url' => route('output.alteracoes', $this->resource),
            'tipo' => $this->tipo,
            'data_entrada' => optional($this->data_entrada)->format('d/m/Y'),
            'numero_referencia' => $ref?->numero_pi ?? $ref?->numero ?? ('#'.$this->referencia_id),
            'qtd_itens' => $ref ? $ref->itens()->count() : 0,
            // Prazo da cotacao: dias entre a data da cotacao e o prazo de resposta (so p/ tipo cotacao).
            'prazo_cotacao' => $this->prazoCotacaoDias($ref),
            // Prazo de fabricacao: maior duracao de Gantt entre os itens (apos alocacao).
            'prazo_fabricacao' => app(GanttService::class)->prazoFabricacaoDemanda($this->resource),
            // Rotulo unico "Cliente – Unidade" (acessor do PI/cotacao); os ids acompanham
            // para os filtros de cliente/unidade da tela.
            'cliente' => $ref?->cliente_com_unidade,
            'cliente_id' => $ref?->cliente_id,
            'unidade_id' => $ref?->unidade_id,
            'escopo' => $ref?->escopo?->descricao,
            'responsavel' => $this->responsavel?->name,
            'responsavel_id' => $this->responsavel_id,
            'data_entrega_eng' => optional($this->data_entrega_engenharia)->format('d/m/Y'),
            'status' => $this->status ? [
                'id' => $this->status->id,
                'nome' => $this->status->nome,
                'cor_hex' => $this->status->cor_hex,
            ] : null,
            'itens' => $ref ? $ref->itens->map(fn ($i) => [
                'numero_item' => $i->numero_item,
                'cod_mmv' => $i->cod_mmv,
                'descricao' => $i->descricao,
                'quantidade' => $i->quantidade,
            ]) : [],
        ];
    }

    /** Dias entre a data da cotacao e o prazo de resposta. Null se nao for cotacao ou faltar data. */
    private function prazoCotacaoDias(?object $ref): ?int
    {
        if ($this->tipo !== 'cotacao' || ! $ref || ! $ref->data_cotacao || ! $ref->prazo_resposta) {
            return null;
        }

        return (int) $ref->data_cotacao->diffInDays($ref->prazo_resposta);
    }
}
