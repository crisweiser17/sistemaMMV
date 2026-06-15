<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use SoftDeletes;

    protected $table = 'materiais';

    protected $fillable = ['tipo_id', 'descricao', 'norma', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoComponente::class, 'tipo_id');
    }
}
