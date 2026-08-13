<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Auditable: o item do PI tambem e impresso na folha de processo (quantidade, NF,
 * descricao). Mudanca aqui depois do PDF conta como alteracao pos-liberacao.
 */
class LiberacaoItem extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'liberacao_itens';

    protected $fillable = [
        'liberacao_id', 'numero_item', 'cod_mmv', 'ni', 'descricao', 'quantidade',
        'unidade_id', 'material_cliente', 'nf_cliente', 'prazo_entrega_item', 'descricao_cliente', 'observacoes',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'prazo_entrega_item' => 'integer',
    ];

    public function liberacao(): BelongsTo
    {
        return $this->belongsTo(Liberacao::class);
    }

    /**
     * NF efetiva do item: a NF propria sobrescreve a do PI; sem NF propria, o item
     * herda a NF do cabecalho. Fonte unica da regra — telas, JSON e PDF leem daqui
     * em vez de repetir o "item ?? PI".
     */
    public function getNfEfetivaAttribute(): ?string
    {
        return filled($this->nf_cliente) ? $this->nf_cliente : $this->liberacao?->nf_cliente;
    }

    /** True quando o item tem NF propria diferente da NF do PI. */
    public function getNfDivergeDoPiAttribute(): bool
    {
        return filled($this->nf_cliente) && $this->nf_cliente !== $this->liberacao?->nf_cliente;
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class, 'unidade_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(LiberacaoItemAnexo::class);
    }
}
