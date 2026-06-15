<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CotacaoAnexo extends Model
{
    use SoftDeletes;

    protected $table = 'cotacao_anexos';

    protected $fillable = ['cotacao_id', 'nome_arquivo', 'path', 'tamanho', 'mime_type', 'criado_por'];

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }
}
