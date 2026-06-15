<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Cotacao extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'cotacoes';

    protected $fillable = [
        'numero', 'numero_cliente', 'cliente_id', 'escopo_id', 'data_cotacao',
        'prazo_resposta', 'nf_cliente', 'observacoes', 'criado_por',
    ];

    protected $casts = [
        'data_cotacao' => 'date',
        'prazo_resposta' => 'date',
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
        return $this->hasMany(CotacaoItem::class);
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(CotacaoAnexo::class);
    }
}
