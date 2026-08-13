<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Auditable pelo mesmo motivo do LiberacaoItem: o item de origem entra no PI
 * impresso, e o detalhamento trata PI e cotacao pelo mesmo caminho
 * (EngenhariaHeader::dadosItemOrigem).
 */
class CotacaoItem extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'cotacao_itens';

    protected $fillable = [
        'cotacao_id', 'numero_item', 'cod_mmv', 'ni', 'descricao', 'quantidade',
        'unidade_id', 'material_cliente', 'descricao_cliente', 'observacoes',
    ];

    protected $casts = ['quantidade' => 'decimal:3'];

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class, 'unidade_id');
    }
}
