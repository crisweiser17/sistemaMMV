<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Demanda extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'demandas';

    protected $fillable = [
        'tipo', 'referencia_id', 'responsavel_id', 'status_id',
        'data_entrada', 'data_entrega_engenharia', 'criado_por',
    ];

    protected $casts = [
        'data_entrada' => 'date',
        'data_entrega_engenharia' => 'date',
    ];

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusEngenharia::class, 'status_id');
    }

    public function headers(): HasMany
    {
        return $this->hasMany(EngenhariaHeader::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class, 'demanda_id')->latest('gerado_em');
    }

    /** Resolve a referencia (Cotacao ou Liberacao) conforme o tipo. */
    public function referencia(): ?Model
    {
        return $this->tipo === 'cotacao'
            ? Cotacao::find($this->referencia_id)
            : Liberacao::find($this->referencia_id);
    }
}
