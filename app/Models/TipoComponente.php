<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TipoComponente extends Model
{
    use SoftDeletes;

    protected $table = 'tipos_componente';

    protected $fillable = ['categoria_id', 'nome', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaComponente::class, 'categoria_id');
    }

    public function materiais(): HasMany
    {
        return $this->hasMany(Material::class, 'tipo_id');
    }
}
