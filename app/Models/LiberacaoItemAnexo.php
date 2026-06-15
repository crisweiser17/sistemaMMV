<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiberacaoItemAnexo extends Model
{
    use SoftDeletes;

    protected $table = 'liberacao_itens_anexos';

    protected $fillable = ['liberacao_item_id', 'nome_arquivo', 'path', 'tamanho', 'mime_type'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LiberacaoItem::class, 'liberacao_item_id');
    }
}
