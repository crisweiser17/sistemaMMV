<?php

namespace App\Events;

use App\Models\Demanda;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado pelo DemandaService apos qualquer mutacao de demanda.
 * A tela de Demandas (liveResource) escuta '.demanda.atualizada' e refaz o fetch.
 * ShouldBroadcastNow: transmite imediatamente (sem depender de queue worker).
 */
class DemandaAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Demanda $demanda) {}

    public function broadcastOn(): Channel
    {
        return new Channel('demandas');
    }

    public function broadcastAs(): string
    {
        return 'demanda.atualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->demanda->id,
            'status_id' => $this->demanda->status_id,
            'responsavel_id' => $this->demanda->responsavel_id,
        ];
    }
}
