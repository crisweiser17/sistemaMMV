<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiberacaoAnexo extends Model
{
    use SoftDeletes;

    protected $table = 'liberacao_anexos';

    protected $fillable = ['liberacao_id', 'nome_arquivo', 'path', 'tamanho', 'mime_type'];

    public function liberacao(): BelongsTo
    {
        return $this->belongsTo(Liberacao::class);
    }
}
