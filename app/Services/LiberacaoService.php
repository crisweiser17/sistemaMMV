<?php

namespace App\Services;

use App\Models\Liberacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Motor de Liberacao (PI): cabecalho + itens (com anexo por item) + anexos gerais,
 * calculo automatico de prazo e geracao da demanda.
 */
class LiberacaoService
{
    public function __construct(private DemandaService $demandas) {}

    public function criar(array $dados, array $itens, int $userId): Liberacao
    {
        return DB::transaction(function () use ($dados, $itens, $userId) {
            $dados['criado_por'] = $userId;
            $dados['prazo_entrega_dias'] = $this->maiorPrazo($itens);
            $liberacao = Liberacao::create($dados);
            $this->sincronizarItens($liberacao, $itens);

            // Regra: ao salvar, gera demanda tipo=liberacao (status Aguardando)
            $this->demandas->gerarParaLiberacao($liberacao);

            return $liberacao->fresh('itens');
        });
    }

    public function atualizar(Liberacao $liberacao, array $dados, array $itens): Liberacao
    {
        return DB::transaction(function () use ($liberacao, $dados, $itens) {
            $dados['prazo_entrega_dias'] = $this->maiorPrazo($itens);
            $liberacao->update($dados);
            $this->sincronizarItens($liberacao, $itens);

            return $liberacao->fresh('itens');
        });
    }

    /** prazo_entrega_dias = maior prazo_entrega_item entre os itens (regra do escopo). */
    private function maiorPrazo(array $itens): ?int
    {
        $prazos = array_filter(array_map(fn ($i) => $i['prazo_entrega_item'] ?? null, $itens), fn ($v) => $v !== null && $v !== '');

        return empty($prazos) ? null : (int) max($prazos);
    }

    private function sincronizarItens(Liberacao $liberacao, array $itens): void
    {
        $manter = [];
        foreach (array_values($itens) as $idx => $item) {
            $payload = [
                'numero_item' => $item['numero_item'] ?? ($idx + 1),
                'cod_mmv' => $item['cod_mmv'] ?? null,
                'ni' => $item['ni'] ?? null,
                'descricao' => $item['descricao'] ?? null,
                'quantidade' => $item['quantidade'] ?? 0,
                'unidade_id' => $item['unidade_id'] ?? null,
                'material_cliente' => $item['material_cliente'] ?? null,
                'prazo_entrega_item' => $item['prazo_entrega_item'] ?? null,
                'descricao_cliente' => $item['descricao_cliente'] ?? null,
                'observacoes' => $item['observacoes'] ?? null,
            ];

            $registro = ! empty($item['id'])
                ? tap($liberacao->itens()->whereKey($item['id'])->first())?->fill($payload)
                : $liberacao->itens()->make($payload);

            if ($registro) {
                $liberacao->itens()->save($registro);
                $manter[] = $registro->id;
            }
        }
        $liberacao->itens()->whereNotIn('id', $manter)->delete();
    }

    public function adicionarAnexoItem(\App\Models\LiberacaoItem $item, \Illuminate\Http\UploadedFile $arquivo): void
    {
        $hash = Str::uuid()->toString();
        $path = $arquivo->storeAs("liberacoes/{$item->liberacao_id}/itens/{$item->id}", $hash.'_'.$arquivo->getClientOriginalName());

        $item->anexos()->create([
            'nome_arquivo' => $arquivo->getClientOriginalName(),
            'path' => $path,
            'tamanho' => $arquivo->getSize(),
            'mime_type' => $arquivo->getMimeType(),
        ]);
    }

    public function adicionarAnexoGeral(Liberacao $liberacao, \Illuminate\Http\UploadedFile $arquivo): void
    {
        $hash = Str::uuid()->toString();
        $path = $arquivo->storeAs("liberacoes/{$liberacao->id}", $hash.'_'.$arquivo->getClientOriginalName());

        $liberacao->anexos()->create([
            'nome_arquivo' => $arquivo->getClientOriginalName(),
            'path' => $path,
            'tamanho' => $arquivo->getSize(),
            'mime_type' => $arquivo->getMimeType(),
        ]);
    }

    public function removerAnexo(\App\Models\LiberacaoAnexo $anexo): void
    {
        Storage::delete($anexo->path);
        $anexo->delete();
    }

    public function removerAnexoItem(\App\Models\LiberacaoItemAnexo $anexo): void
    {
        Storage::delete($anexo->path);
        $anexo->delete();
    }
}
