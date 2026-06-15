<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CategoriaComponente extends Model
{
    use SoftDeletes;

    protected $table = 'categorias_componente';

    protected $fillable = ['nome', 'tipo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function tipos(): HasMany
    {
        return $this->hasMany(TipoComponente::class, 'categoria_id');
    }
}
