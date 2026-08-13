<?php

namespace App\Services;

use App\Models\Cotacao;
use App\Models\Liberacao;
use Illuminate\Support\Facades\DB;

/**
 * Motor de Cotacao: persistencia de cabecalho + itens + anexos, busca por NI
 * e geracao automatica da demanda. Isolado de HTTP.
 */
class CotacaoService
{
    public function __construct(
        private DemandaService $demandas,
        private AnexoService $anexos,
    ) {}

    public function criar(array $dados, array $itens, int $userId): Cotacao
    {
        return DB::transaction(function () use ($dados, $itens, $userId) {
            $dados['criado_por'] = $userId;
            $cotacao = Cotacao::create($dados);
            $this->sincronizarItens($cotacao, $itens);

            // Regra: ao salvar, gera demanda tipo=cotacao
            $this->demandas->gerarParaCotacao($cotacao);

            return $cotacao->fresh('itens');
        });
    }

    public function atualizar(Cotacao $cotacao, array $dados, array $itens): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $dados, $itens) {
            $cotacao->update($dados);
            $this->sincronizarItens($cotacao, $itens);

            return $cotacao->fresh('itens');
        });
    }

    private function sincronizarItens(Cotacao $cotacao, array $itens): void
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
                'descricao_cliente' => $item['descricao_cliente'] ?? null,
                'observacoes' => $item['observacoes'] ?? null,
            ];

            $registro = ! empty($item['id'])
                ? tap($cotacao->itens()->whereKey($item['id'])->first())?->fill($payload)
                : $cotacao->itens()->make($payload);

            if ($registro) {
                $cotacao->itens()->save($registro);
                $manter[] = $registro->id;
            }
        }
        // Remove itens excluidos no formulario
        $cotacao->itens()->whereNotIn('id', $manter)->delete();
    }

    public function adicionarAnexo(Cotacao $cotacao, \Illuminate\Http\UploadedFile $arquivo, int $userId): void
    {
        $path = $this->anexos->guardar($arquivo, "cotacoes/{$cotacao->id}");

        $cotacao->anexos()->create([
            'nome_arquivo' => $arquivo->getClientOriginalName(),
            'path' => $path,
            'tamanho' => $arquivo->getSize(),
            'mime_type' => $arquivo->getMimeType(),
            'criado_por' => $userId,
        ]);
    }

    public function removerAnexo(\App\Models\CotacaoAnexo $anexo): void
    {
        $this->anexos->apagar($anexo->path);
        $anexo->delete();
    }

    /**
     * Historico por NI: cotacoes e liberacoes anteriores que contem a peca.
     * Consumido pela fabrica Alpine niLookup (modal de historico).
     */
    public function historicoPorNi(string $ni): array
    {
        $cotacoes = Cotacao::whereHas('itens', fn ($q) => $q->where('ni', $ni))
            ->with(['cliente', 'unidade', 'itens' => fn ($q) => $q->where('ni', $ni)])
            ->latest()->limit(20)->get()
            ->map(fn ($c) => [
                'origem' => 'Cotação',
                'numero' => $c->numero ?? ('#'.$c->id),
                'cliente' => $c->cliente_com_unidade,
                'data' => optional($c->data_cotacao)->format('d/m/Y'),
                'itens' => $c->itens->map(fn ($i) => ['descricao' => $i->descricao, 'quantidade' => $i->quantidade])->all(),
            ]);

        $liberacoes = Liberacao::whereHas('itens', fn ($q) => $q->where('ni', $ni))
            ->with(['cliente', 'unidade', 'itens' => fn ($q) => $q->where('ni', $ni)])
            ->latest()->limit(20)->get()
            ->map(fn ($l) => [
                'origem' => 'Liberação',
                'numero' => $l->numero_pi ?? ('#'.$l->id),
                'cliente' => $l->cliente_com_unidade,
                'data' => optional($l->data_pedido)->format('d/m/Y'),
                'itens' => $l->itens->map(fn ($i) => ['descricao' => $i->descricao, 'quantidade' => $i->quantidade])->all(),
            ]);

        return $cotacoes->concat($liberacoes)->values()->all();
    }
}
