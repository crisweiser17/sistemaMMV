<?php

namespace App\Events;

use App\Models\Demanda;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado quando um processo JA LIBERADO (ja teve PDF do PI gerado) recebe uma
 * mudanca. Producao e compras precisam saber que a folha que esta na mao delas
 * ficou desatualizada: "Houve alteracao no processo 1173".
 *
 * Canal publico 'processos': nao existe perfil de Producao nem de Compras no
 * cadastro, entao nao ha por quem segmentar — todo usuario logado recebe o aviso.
 * ShouldBroadcastNow: transmite imediatamente (sem depender de queue worker).
 */
class ProcessoAlterado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Demanda $demanda,
        public string $numeroReferencia,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('processos');
    }

    public function broadcastAs(): string
    {
        return 'processo.alterado';
    }

    public function broadcastWith(): array
    {
        return [
            'demanda_id' => $this->demanda->id,
            'numero_referencia' => $this->numeroReferencia,
            // Texto no formato pedido pelo cliente; o front so joga no toast.
            'mensagem' => "Houve alteração no processo {$this->numeroReferencia}",
            'url' => route('output.alteracoes', $this->demanda),
        ];
    }
}
