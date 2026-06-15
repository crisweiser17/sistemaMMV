<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnidadeMedida extends Model
{
    use SoftDeletes;

    protected $table = 'unidades_medida';

    protected $fillable = ['sigla', 'descricao', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];
}
