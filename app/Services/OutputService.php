<?php

namespace App\Services;

use App\Models\Demanda;
use App\Models\Output;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Motor de Output: monta os dados do documento PI da DEMANDA (um grupo de linhas
 * de detalhamento por item) e gera/armazena o PDF, mantendo historico de versoes.
 */
class OutputService
{
    /** Estrutura de dados do PI consumida tanto pelo preview quanto pelo PDF. */
    public function montarDados(Demanda $demanda): array
    {
        $demanda->load([
            'headers.linhas.material', 'headers.linhas.unidade', 'headers.linhas.categoria',
            'headers.status', 'headers.responsavel', 'headers.cliente',
        ]);

        $referencia = $demanda->referencia();
        $headers = $demanda->headers;

        // Um bloco por item (header), com as linhas agrupadas por tipo de componente.
        $itens = $headers->map(function ($h) {
            $linhas = $h->linhas;

            return [
                'header' => $h,
                'materia_prima' => $linhas->where('tipo_componente', 'materia_prima')->values(),
                'mao_de_obra' => $linhas->where('tipo_componente', 'servico')->values(),
                'comerciais' => $linhas->where('tipo_componente', 'comercial')->values(),
                'insumos' => $linhas->whereNull('tipo_componente')->values(),
            ];
        });

        $primeiro = $headers->first();

        return [
            'demanda' => $demanda,
            'referencia' => $referencia,
            'cliente' => $primeiro?->cliente ?? $referencia?->cliente,
            'numeroReferencia' => $primeiro?->numero_referencia ?? ('Demanda #'.$demanda->id),
            'itens' => $itens,
            'gerado_em' => now(),
        ];
    }

    public function gerarPdf(Demanda $demanda, int $userId): Output
    {
        $dados = $this->montarDados($demanda);

        $pdf = Pdf::loadView('output.pi', $dados)->setPaper('a4', 'portrait');

        $timestamp = now()->format('Ymd_His');
        $path = "outputs/demanda/{$demanda->id}/{$timestamp}.pdf";
        Storage::put($path, $pdf->output());

        return Output::create([
            'demanda_id' => $demanda->id,
            'gerado_por' => $userId,
            'gerado_em' => now(),
            'path_pdf' => $path,
        ]);
    }
}
