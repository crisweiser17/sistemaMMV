<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Output extends Model
{
    protected $table = 'outputs';

    protected $fillable = ['demanda_id', 'header_id', 'gerado_por', 'gerado_em', 'path_pdf'];

    protected $casts = ['gerado_em' => 'datetime'];

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class, 'demanda_id');
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(EngenhariaHeader::class, 'header_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerado_por');
    }
}
