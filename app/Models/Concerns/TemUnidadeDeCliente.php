<?php

namespace App\Models\Concerns;

use App\Models\Cliente;
use App\Models\ClienteUnidade;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro que pertence a um cliente e, opcionalmente, a uma unidade dele.
 * Fonte unica do rotulo "Cliente - Unidade" usado em telas, listagens, JSON e PDF:
 * quem exibe cliente le $registro->cliente_com_unidade em vez de concatenar.
 */
trait TemUnidadeDeCliente
{
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(ClienteUnidade::class, 'unidade_id');
    }

    /** Rotulo de exibicao; null quando o registro nao tem cliente. */
    public function getClienteComUnidadeAttribute(): ?string
    {
        return Cliente::rotulo($this->cliente?->nome, $this->unidade?->nome);
    }
}
