<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EngenhariaLinha extends Model
{
    use SoftDeletes;

    protected $table = 'engenharia_linhas';

    protected $fillable = [
        'header_id', 'numero_linha', 'cod_mmv', 'descricao', 'arquivo_path', 'local_referencia',
        'escopo_id', 'tipo_componente', 'categoria_componente_id', 'tipo_componente_id',
        'material_id', 'mao_de_obra', 'quantidade', 'duracao_dias', 'unidade_id', 'observacao', 'fase', 'status',
    ];

    protected $casts = ['quantidade' => 'decimal:3', 'duracao_dias' => 'integer'];

    public function header(): BelongsTo
    {
        return $this->belongsTo(EngenhariaHeader::class, 'header_id');
    }

    public function escopo(): BelongsTo
    {
        return $this->belongsTo(Escopo::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaComponente::class, 'categoria_componente_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoComponente::class, 'tipo_componente_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(UnidadeMedida::class, 'unidade_id');
    }

    /** Linhas das quais esta linha depende. */
    public function dependencias(): BelongsToMany
    {
        return $this->belongsToMany(
            EngenhariaLinha::class,
            'engenharia_dependencias',
            'linha_id',
            'depende_de_linha_id'
        )->withTimestamps();
    }
}
