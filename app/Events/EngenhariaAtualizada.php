<?php

namespace App\Events;

use App\Models\EngenhariaHeader;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado quando linhas de um item de engenharia mudam (add/edit/del/finalizar).
 * A tela de detalhamento escuta 'engenharia.{headerId}' e reflete sem reload.
 * ShouldBroadcastNow: transmite imediatamente (sem depender de queue worker).
 */
class EngenhariaAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public EngenhariaHeader $header) {}

    public function broadcastOn(): Channel
    {
        return new Channel('engenharia.'.$this->header->id);
    }

    public function broadcastAs(): string
    {
        return 'engenharia.atualizada';
    }

    public function broadcastWith(): array
    {
        return ['header_id' => $this->header->id, 'status_id' => $this->header->status_id];
    }
}
