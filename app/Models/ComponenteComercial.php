<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComponenteComercial extends Model
{
    use SoftDeletes;

    protected $table = 'componentes_comerciais';

    protected $fillable = ['categoria_id', 'tipo', 'especificacao', 'padrao', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaComponente::class, 'categoria_id');
    }
}
