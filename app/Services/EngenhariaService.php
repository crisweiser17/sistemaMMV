<?php

namespace App\Services;

use App\Events\EngenhariaAtualizada;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\StatusEngenharia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Motor de Engenharia: linhas de detalhamento, dependencias entre linhas,
 * finalizacao do item e propagacao do status para a demanda.
 */
class EngenhariaService
{
    public function __construct(private DemandaService $demandas) {}

    public function adicionarLinha(EngenhariaHeader $header, array $dados): EngenhariaLinha
    {
        $dados['numero_linha'] = $dados['numero_linha'] ?? (((int) $header->linhas()->max('numero_linha')) + 1);
        $linha = $header->linhas()->create($dados);

        // Garante status "Em andamento" ao iniciar detalhamento.
        if (! $header->status || $header->status->nome === 'A iniciar') {
            $header->update(['status_id' => $this->statusId('Em andamento')]);
        }

        $this->emitir(new EngenhariaAtualizada($header->fresh()));

        return $linha;
    }

    public function atualizarLinha(EngenhariaLinha $linha, array $dados): EngenhariaLinha
    {
        $linha->update($dados);
        $this->emitir(new EngenhariaAtualizada($linha->header));

        return $linha->fresh();
    }

    public function removerLinha(EngenhariaLinha $linha): void
    {
        $header = $linha->header;
        $linha->delete();
        $this->emitir(new EngenhariaAtualizada($header->fresh()));
    }

    /** Registra dependencia M:N entre linhas (linha depende de outra). */
    public function adicionarDependencia(EngenhariaLinha $linha, int $dependeDeLinhaId): void
    {
        if ($dependeDeLinhaId !== $linha->id) {
            $linha->dependencias()->syncWithoutDetaching([$dependeDeLinhaId]);
            $this->emitir(new EngenhariaAtualizada($linha->header));
        }
    }

    /** Define dependencias a partir de uma lista (ex.: "2,3" -> numeros de linha do mesmo header). */
    public function definirDependenciasPorNumeros(EngenhariaLinha $linha, array $numeros): void
    {
        $ids = $linha->header->linhas()
            ->whereIn('numero_linha', $numeros)
            ->where('id', '!=', $linha->id)
            ->pluck('id')->all();

        $linha->dependencias()->sync($ids);
        $this->emitir(new EngenhariaAtualizada($linha->header));
    }

    /** Finaliza o item; recalcula o status da demanda (todos finalizados => demanda Finalizada). */
    public function finalizar(EngenhariaHeader $header): EngenhariaHeader
    {
        return DB::transaction(function () use ($header) {
            $header->update(['status_id' => $this->statusId('Finalizado')]);
            $this->emitir(new EngenhariaAtualizada($header->fresh()));

            $this->demandas->recalcularStatus($header->demanda);

            return $header->fresh();
        });
    }

    public function anexarArquivoLinha(EngenhariaLinha $linha, \Illuminate\Http\UploadedFile $arquivo): EngenhariaLinha
    {
        $hash = Str::uuid()->toString();
        $path = $arquivo->storeAs("engenharia/{$linha->header_id}/{$linha->id}", $hash.'_'.$arquivo->getClientOriginalName());
        $linha->update(['arquivo_path' => $path]);

        return $linha->fresh();
    }

    public function removerArquivoLinha(EngenhariaLinha $linha): EngenhariaLinha
    {
        if ($linha->arquivo_path) {
            Storage::delete($linha->arquivo_path);
            $linha->update(['arquivo_path' => null]);
        }

        return $linha->fresh();
    }

    private function statusId(string $nome): ?int
    {
        return StatusEngenharia::where('nome', $nome)->value('id');
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
}
