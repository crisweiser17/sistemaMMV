<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcessoFabricacao extends Model
{
    use SoftDeletes;

    protected $table = 'processos_fabricacao';

    protected $fillable = ['macro_processo', 'processo', 'sub_processo', 'descricao_servico', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];
}
