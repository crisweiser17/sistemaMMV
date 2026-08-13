<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Unidade (planta/site) de um cliente — ex.: Suzano SA -> Tres Lagoas.
 * Substitui a coluna legada clientes.unidade, que so permitia uma por cliente.
 */
class ClienteUnidade extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'cliente_unidades';

    protected $fillable = ['cliente_id', 'nome', 'codigo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
