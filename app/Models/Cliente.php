<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Cliente extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $fillable = ['nome', 'codigo_pa', 'unidade', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];
}
