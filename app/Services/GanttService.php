<?php

namespace App\Services;

use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Monta os dados do Gantt (frappe-gantt) a partir das linhas e dependencias.
 * Cada linha tem sua propria duracao (campo duracao_dias, em DIAS UTEIS) e e
 * agendada sequencialmente respeitando a ordem topologica das dependencias.
 * Fins de semana sao pulados; feriados nao sao considerados.
 */
class GanttService
{
    private const DURACAO_PADRAO = 2;

    public function dados(EngenhariaHeader $header): array
    {
        $linhas = $header->linhas()->with('dependencias')->get()->keyBy('id');
        // Base agendada no primeiro dia util (>= data de alocacao).
        $base = Carbon::parse($header->data_alocacao ?? now())->startOfDay();
        if ($base->isWeekend()) {
            $base = $base->nextWeekday();
        }

        $fim = [];   // id => Carbon fim (memoizacao)
        $tasks = [];

        // Resolve a data fim de uma linha respeitando suas dependencias (em dias uteis).
        $resolver = function ($id) use (&$resolver, $linhas, $base, &$fim) {
            if (isset($fim[$id])) {
                return $fim[$id];
            }
            $linha = $linhas[$id];
            $inicio = $base->copy();
            foreach ($linha->dependencias as $dep) {
                if (isset($linhas[$dep->id])) {
                    $depFim = $resolver($dep->id);
                    if ($depFim->gt($inicio)) {
                        $inicio = $depFim->copy();
                    }
                }
            }

            return $fim[$id] = $inicio->copy()->addWeekdays($this->duracaoLinha($linha));
        };

        foreach ($linhas as $id => $linha) {
            $fimData = $resolver($id);
            $inicioData = $fimData->copy()->subWeekdays($this->duracaoLinha($linha));

            $tasks[] = [
                'id' => (string) $linha->id,
                'name' => 'L'.$linha->numero_linha.' · '.Str::limit($linha->descricao ?? 'Etapa', 40),
                'start' => $inicioData->format('Y-m-d'),
                'end' => $fimData->format('Y-m-d'),
                'progress' => $linha->status === 'Finalizado' ? 100 : 0,
                'dependencies' => $linha->dependencias->pluck('id')->implode(','),
            ];
        }

        return $tasks;
    }

    /** Duracao de uma linha em dias uteis (default quando nao informada). */
    private function duracaoLinha(EngenhariaLinha $linha): int
    {
        return max(1, (int) ($linha->duracao_dias ?? self::DURACAO_PADRAO));
    }

    /**
     * Duracao do Gantt de um header, em DIAS UTEIS (da data base ate a ultima
     * data de fim do cronograma). Equivale ao comprimento do caminho critico.
     */
    public function duracaoDias(EngenhariaHeader $header): int
    {
        $tasks = $this->dados($header);
        if (empty($tasks)) {
            return 0;
        }

        $base = Carbon::parse($header->data_alocacao ?? now())->startOfDay();
        if ($base->isWeekend()) {
            $base = $base->nextWeekday();
        }
        $fim = collect($tasks)->map(fn ($t) => Carbon::parse($t['end']))->max();

        return (int) $base->diffInWeekdays($fim);
    }

    /**
     * Prazo de fabricacao da demanda = maior duracao de Gantt entre os itens
     * (headers de engenharia). Retorna null se ainda nao houver cronograma.
     */
    public function prazoFabricacaoDemanda(Demanda $demanda): ?int
    {
        $max = 0;
        foreach ($demanda->headers as $header) {
            $max = max($max, $this->duracaoDias($header));
        }

        return $max > 0 ? $max : null;
    }
}
