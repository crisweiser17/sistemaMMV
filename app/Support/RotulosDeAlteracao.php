<?php

namespace App\Support;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Escopo;
use App\Models\Liberacao;
use App\Models\LiberacaoItem;
use App\Models\Material;
use App\Models\StatusEngenharia;
use App\Models\TipoComponente;
use App\Models\UnidadeMedida;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Traducao do dado cru da auditoria para o que o usuario le: nome do campo, rotulo
 * do registro e valor por extenso (chave estrangeira vira texto).
 *
 * Fica separado do AlteracaoService de proposito: la mora a deteccao (o que mudou),
 * aqui mora a apresentacao (como se escreve o que mudou).
 */
final class RotulosDeAlteracao
{
    /** Nome legivel de cada coluna auditada. Vale para todos os modelos. */
    private const CAMPOS = [
        'quantidade' => 'Quantidade',
        'descricao' => 'Descrição',
        'descricao_cliente' => 'Descrição do cliente',
        'cod_mmv' => 'Cód. MMV',
        'ni' => 'NI',
        'numero_item' => 'Nº do item',
        'numero_pi' => 'Nº do PI',
        'numero_pc' => 'Nº do PC',
        'numero' => 'Nº da cotação',
        'numero_referencia' => 'Nº de referência',
        'nome_item' => 'Nome do item',
        'material_id' => 'Material',
        'material_cliente' => 'Material do cliente',
        'mao_de_obra' => 'Mão de obra',
        'unidade_id' => 'Unidade',
        'observacao' => 'Observação',
        'observacoes' => 'Observações',
        'fase' => 'Fase',
        'duracao_dias' => 'Duração (dias)',
        'escopo_id' => 'Escopo',
        'categoria_componente_id' => 'Categoria do componente',
        'tipo_componente_id' => 'Tipo de componente',
        'tipo_componente' => 'Grupo do componente',
        'local_referencia' => 'Local / referência',
        'arquivo_path' => 'Desenho anexado',
        'responsavel_id' => 'Responsável',
        'data_alocacao' => 'Data de alocação',
        'cliente_id' => 'Cliente',
        'status_id' => 'Status',
        'nf_cliente' => 'NF do cliente',
        'prazo_entrega_dias' => 'Prazo de entrega (dias)',
        'prazo_entrega_item' => 'Prazo do item (dias)',
        'data_pedido' => 'Data do pedido',
        'data_entrega_cliente' => 'Entrega ao cliente',
        'data_cotacao' => 'Data da cotação',
        'prazo_resposta' => 'Prazo de resposta',
    ];

    /**
     * Chaves estrangeiras que viram texto, por modelo — a mesma coluna aponta para
     * cadastros diferentes conforme a tabela (unidade_id e unidade de medida na
     * linha e unidade do cliente no PI).
     *
     * @var array<class-string, array<string, array{0: class-string, 1: string}>>
     */
    private const RELACOES = [
        EngenhariaLinha::class => [
            // especificacao_completa: rotulo unico do Cadastro, o mesmo impresso no PI.
            'material_id' => [Material::class, 'especificacao_completa'],
            'unidade_id' => [UnidadeMedida::class, 'sigla'],
            'escopo_id' => [Escopo::class, 'descricao'],
            'categoria_componente_id' => [CategoriaComponente::class, 'nome'],
            'tipo_componente_id' => [TipoComponente::class, 'nome'],
        ],
        EngenhariaHeader::class => [
            'responsavel_id' => [User::class, 'name'],
            'cliente_id' => [Cliente::class, 'nome'],
            'unidade_id' => [ClienteUnidade::class, 'nome'],
            'status_id' => [StatusEngenharia::class, 'nome'],
        ],
        LiberacaoItem::class => [
            'unidade_id' => [UnidadeMedida::class, 'sigla'],
        ],
        CotacaoItem::class => [
            'unidade_id' => [UnidadeMedida::class, 'sigla'],
        ],
        Liberacao::class => [
            'cliente_id' => [Cliente::class, 'nome'],
            'unidade_id' => [ClienteUnidade::class, 'nome'],
            'escopo_id' => [Escopo::class, 'descricao'],
        ],
        Cotacao::class => [
            'cliente_id' => [Cliente::class, 'nome'],
            'unidade_id' => [ClienteUnidade::class, 'nome'],
            'escopo_id' => [Escopo::class, 'descricao'],
        ],
    ];

    /** Texto usado quando o campo esta vazio dos dois lados da comparacao. */
    public const VAZIO = '—';

    /** @var array<string, ?string> cache dos rotulos ja resolvidos nesta requisicao */
    private array $cache = [];

    public static function campo(string $campo): string
    {
        return self::CAMPOS[$campo] ?? ucfirst(str_replace('_', ' ', $campo));
    }

    /**
     * Como o registro se chama para quem le o historico
     * (ex.: "Linha 3 — Corte da chapa").
     */
    public static function registro(Model $registro): string
    {
        return match (true) {
            $registro instanceof EngenhariaLinha => self::juntar('Linha '.$registro->numero_linha, $registro->descricao),
            $registro instanceof EngenhariaHeader => self::juntar('Item', $registro->nome_item),
            $registro instanceof LiberacaoItem, $registro instanceof CotacaoItem => self::juntar('Item '.$registro->numero_item.' do pedido', $registro->descricao),
            $registro instanceof Liberacao => 'PI '.$registro->numero_pi,
            $registro instanceof Cotacao => 'Cotação '.$registro->numero,
            default => class_basename($registro).' #'.$registro->getKey(),
        };
    }

    /** Valor por extenso: resolve chave estrangeira e limpa decimal do banco. */
    public function valor(string $modelo, string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return self::VAZIO;
        }

        [$classe, $atributo] = self::RELACOES[$modelo][$campo] ?? [null, null];

        if ($classe !== null) {
            return $this->rotuloRelacionado($classe, $atributo, $valor) ?? (string) $valor;
        }

        $texto = (string) $valor;

        // "3.000" gravado pelo cast decimal volta a ser o "3" que o usuario digitou.
        return is_numeric($texto) && str_contains($texto, '.')
            ? rtrim(rtrim($texto, '0'), '.')
            : $texto;
    }

    /**
     * @param  class-string  $classe
     */
    private function rotuloRelacionado(string $classe, string $atributo, mixed $id): ?string
    {
        $chave = $classe.'#'.$id;

        if (! array_key_exists($chave, $this->cache)) {
            $registro = $classe::find($id);
            $rotulo = $registro?->{$atributo};
            $this->cache[$chave] = filled($rotulo) ? (string) $rotulo : null;
        }

        return $this->cache[$chave];
    }

    private static function juntar(string $prefixo, ?string $complemento): string
    {
        return filled($complemento) ? $prefixo.' — '.$complemento : $prefixo;
    }
}
