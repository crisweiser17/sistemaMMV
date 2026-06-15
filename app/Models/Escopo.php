<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Escopo extends Model
{
    use SoftDeletes;

    protected $fillable = ['codigo', 'descricao', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];
}
