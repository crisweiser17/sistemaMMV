<?php

namespace App\Services;

use App\Events\DemandaAtualizada;
use App\Models\Cotacao;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use App\Models\StatusEngenharia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Motor do Controle de Demandas: geracao automatica, alocacao para engenharia
 * e recalculo de status. Sem dependencia de HTTP — reutilizavel/testavel.
 */
class DemandaService
{
    private function status(string $nome): ?StatusEngenharia
    {
        return StatusEngenharia::where('nome', $nome)->first();
    }

    /** Transmite o evento de forma resiliente: falha de broadcast (Reverb fora) nao quebra a operacao. */
    private function emitir(object $evento): void
    {
        try {
            event($evento);
        } catch (\Throwable $e) {
            Log::warning('Broadcast falhou: '.$e->getMessage());
        }
    }

    /** Cria a demanda de uma cotacao recem-salva. */
    public function gerarParaCotacao(Cotacao $cotacao): Demanda
    {
        return Demanda::create([
            'tipo' => 'cotacao',
            'referencia_id' => $cotacao->id,
            'status_id' => $this->status('A iniciar')?->id,
            'data_entrada' => now()->toDateString(),
            'criado_por' => $cotacao->criado_por,
        ]);
    }

    /** Cria a demanda de um PI recem-salvo (status inicial Aguardando). */
    public function gerarParaLiberacao(Liberacao $liberacao): Demanda
    {
        return Demanda::create([
            'tipo' => 'liberacao',
            'referencia_id' => $liberacao->id,
            'status_id' => $this->status('Aguardando')?->id,
            'data_entrada' => now()->toDateString(),
            'criado_por' => $liberacao->criado_por,
        ]);
    }

    /**
     * Aloca responsavel e gera um EngenhariaHeader por item da referencia (PI/cotacao).
     * Muda o status para "Em andamento".
     */
    public function alocar(Demanda $demanda, int $responsavelId): Demanda
    {
        return DB::transaction(function () use ($demanda, $responsavelId) {
            $referencia = $demanda->referencia();
            $emAndamento = $this->status('Em andamento');

            $demanda->update([
                'responsavel_id' => $responsavelId,
                'status_id' => $emAndamento?->id,
            ]);

            // Gera headers apenas se ainda nao existirem (idempotente).
            if ($demanda->headers()->count() === 0 && $referencia) {
                foreach ($referencia->itens as $item) {
                    EngenhariaHeader::create([
                        'demanda_id' => $demanda->id,
                        'responsavel_id' => $responsavelId,
                        'cliente_id' => $referencia->cliente_id,
                        // O header herda a unidade do PI/cotacao de origem.
                        'unidade_id' => $referencia->unidade_id,
                        'numero_referencia' => $referencia->numero_pi ?? $referencia->numero ?? ('#'.$referencia->id),
                        'nome_item' => $item->descricao,
                        'data_alocacao' => now()->toDateString(),
                        'status_id' => $this->status('A iniciar')?->id,
                        'liberacao_item_id' => $demanda->tipo === 'liberacao' ? $item->id : null,
                        'cotacao_item_id' => $demanda->tipo === 'cotacao' ? $item->id : null,
                    ]);
                }
            }

            $this->emitir(new DemandaAtualizada($demanda->fresh()));

            return $demanda->fresh();
        });
    }

    /** Atualiza status manualmente. */
    public function atualizarStatus(Demanda $demanda, int $statusId): Demanda
    {
        $demanda->update(['status_id' => $statusId]);
        $this->emitir(new DemandaAtualizada($demanda->fresh()));

        return $demanda->fresh();
    }

    /**
     * Recalcula o status da demanda a partir dos headers de engenharia.
     * Se todos os itens estiverem finalizados, marca a demanda como Finalizado.
     */
    public function recalcularStatus(Demanda $demanda): Demanda
    {
        $finalizado = $this->status('Finalizado');
        $headers = $demanda->headers()->get();

        if ($headers->isNotEmpty() && $finalizado && $headers->every(fn ($h) => $h->status_id === $finalizado->id)) {
            $demanda->update([
                'status_id' => $finalizado->id,
                'data_entrega_engenharia' => now()->toDateString(),
            ]);
            $this->emitir(new DemandaAtualizada($demanda->fresh()));
        }

        return $demanda->fresh();
    }
}
