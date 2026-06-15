<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatusEngenharia extends Model
{
    use SoftDeletes;

    protected $table = 'status_engenharia';

    protected $fillable = ['nome', 'cor_hex', 'ordem', 'ativo'];

    protected $casts = ['ativo' => 'boolean', 'ordem' => 'integer'];
}
