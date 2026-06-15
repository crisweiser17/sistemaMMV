<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EngenhariaHeader extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'engenharia_headers';

    protected $fillable = [
        'demanda_id', 'responsavel_id', 'cliente_id', 'numero_referencia',
        'nome_item', 'data_alocacao', 'status_id', 'liberacao_item_id', 'cotacao_item_id',
    ];

    protected $casts = ['data_alocacao' => 'date'];

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusEngenharia::class, 'status_id');
    }

    /** Item da cotacao de origem (itens removidos ainda aparecem no detalhamento). */
    public function itemCotacao(): BelongsTo
    {
        return $this->belongsTo(CotacaoItem::class, 'cotacao_item_id')->withTrashed();
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(EngenhariaLinha::class, 'header_id')->orderBy('numero_linha');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class, 'header_id')->latest('gerado_em');
    }
}
