<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Liberacao extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'liberacoes';

    protected $fillable = [
        'numero_pi', 'numero_pc', 'cliente_id', 'escopo_id', 'data_pedido', 'nf_cliente',
        'prazo_entrega_dias', 'data_entrega_cliente', 'observacoes', 'criado_por',
    ];

    protected $casts = [
        'data_pedido' => 'date',
        'data_entrega_cliente' => 'date',
        'prazo_entrega_dias' => 'integer',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function escopo(): BelongsTo
    {
        return $this->belongsTo(Escopo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(LiberacaoItem::class);
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(LiberacaoAnexo::class);
    }
}
