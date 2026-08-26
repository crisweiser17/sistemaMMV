<?php

namespace App\Services;

use Illuminate\Validation\Rule;

/**
 * Numero de pedido (numero do PI e numero da cotacao) e unico GLOBALMENTE: nao por
 * cliente e nao por ano — dois clientes diferentes nao podem repetir o mesmo numero.
 *
 * Aqui mora so a regra de validacao usada pelos dois controllers. A garantia real
 * contra dois salvamentos simultaneos e o indice unico criado na migration
 * 2026_08_26_000001_adiciona_unicidade_a_numeros_de_pedido.
 */
class NumeroPedidoService
{
    /** Mesmo limite de tamanho ja praticado pelos outros campos de numero do cabecalho. */
    private const TAMANHO_MAXIMO = 100;

    /**
     * Regra de unicidade global do numero do pedido.
     *
     * @param  string  $tabela  liberacoes | cotacoes
     * @param  string  $coluna  numero_pi | numero
     * @param  int|null  $ignorarId  id do proprio registro quando e edicao. Sem isso,
     *                               salvar um PI existente sem mexer no numero
     *                               acusaria duplicidade contra ele mesmo.
     * @return array<int, mixed>
     */
    public static function regraDeUnicidade(string $tabela, string $coluna, ?int $ignorarId = null): array
    {
        // whereNull(deleted_at): registro em soft delete nao segura o numero de volta.
        // E exatamente o mesmo recorte do indice parcial criado no banco.
        $unico = Rule::unique($tabela, $coluna)->whereNull('deleted_at');

        if ($ignorarId !== null) {
            $unico = $unico->ignore($ignorarId);
        }

        // 'nullable' na frente faz o unique ser pulado quando o campo vem vazio, entao
        // registro sem numero nunca colide com outro sem numero (NULL nao colide com
        // NULL, nem na validacao nem no indice).
        return ['nullable', 'string', 'max:'.self::TAMANHO_MAXIMO, $unico];
    }
}
