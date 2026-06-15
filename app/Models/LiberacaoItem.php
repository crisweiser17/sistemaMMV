<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiberacaoItem extends Model
{
    use SoftDeletes;

    protected $table = 'liberacao_itens';

    protected $fillable = [
        'liberacao_id', 'numero_item', 'cod_mmv', 'ni', 'descricao', 'quantidade',
        'unidade_id', 'material_cliente', 'prazo_entrega_item', 'descricao_cliente', 'observacoes',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'prazo_entrega_item' => 'integer',
    ];

    public function liberacao(): BelongsTo
    {
        return $this->belongsTo(Liberacao::class);
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
