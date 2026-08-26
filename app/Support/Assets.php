<?php

namespace App\Support;

/**
 * URL de asset estatico com marca de versao.
 *
 * O servidor manda `cache-control: public, max-age=31536000` nos arquivos de
 * public/, entao um deploy que troca o JS nao chega em quem ja visitou o site:
 * o navegador segue usando a copia velha por um ano. Sem build step nao ha
 * hash no nome do arquivo, entao a versao vai na query string.
 *
 * Usar a data de modificacao (e nao um numero fixo) faz a URL mudar sozinha a
 * cada deploy, sem ninguem precisar lembrar de incrementar nada.
 */
class Assets
{
    /** Cache em memoria: o mesmo arquivo costuma ser pedido mais de uma vez por request. */
    private static array $versoes = [];

    public static function versionado(string $caminhoRelativo): string
    {
        $caminhoRelativo = ltrim($caminhoRelativo, '/');

        if (! isset(self::$versoes[$caminhoRelativo])) {
            $absoluto = public_path($caminhoRelativo);

            // Arquivo ausente: devolve a URL sem versao em vez de quebrar a pagina.
            self::$versoes[$caminhoRelativo] = is_file($absoluto)
                ? (string) filemtime($absoluto)
                : '';
        }

        $versao = self::$versoes[$caminhoRelativo];
        $url = asset($caminhoRelativo);

        return $versao === '' ? $url : $url.'?v='.$versao;
    }
}
