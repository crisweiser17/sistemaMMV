<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Regras e persistencia de anexos (desenhos tecnicos e documentos).
 *
 * Centraliza num unico lugar: disco usado, extensoes aceitas, limite de tamanho
 * e montagem do nome do arquivo. Assim gravacao e leitura nunca divergem e as
 * quatro telas que aceitam anexo (engenharia, liberacao item, liberacao PI e
 * cotacao) validam exatamente da mesma forma.
 */
class AnexoService
{
    /**
     * Disco explicito. Nao usa o disco padrao (FILESYSTEM_DISK) de proposito:
     * trocar o env nao pode orfanar os anexos ja gravados. O root do disco
     * "local" no Laravel 11+ e storage/app/private, que e onde os anexos
     * existentes ja estao.
     */
    public const DISCO = 'local';

    /** Extensoes de desenho tecnico e documentos aceitas. */
    public const EXTENSOES = ['pdf', 'dwg', 'dxf', 'step', 'stp', 'png', 'jpg', 'jpeg'];

    /** Limite por arquivo, em kilobytes (50 MB). Desenho 3D (STEP) passa de 20 MB com facilidade. */
    public const MAX_KB = 51200;

    /** Tamanho maximo do nome original preservado (a coluna de path tem 255 chars). */
    private const MAX_NOME = 120;

    /** Regras de validacao do campo de upload. */
    public static function regras(string $campo = 'arquivo'): array
    {
        return [
            $campo => [
                'required',
                'file',
                'max:'.self::MAX_KB,
                'extensions:'.implode(',', self::EXTENSOES),
            ],
        ];
    }

    /** Mensagens explicitas: o usuario precisa saber o limite e as extensoes. */
    public static function mensagens(string $campo = 'arquivo'): array
    {
        return [
            $campo.'.required' => 'Selecione um arquivo para anexar.',
            $campo.'.file' => 'O envio falhou (arquivo nao chegou ao servidor). Verifique o tamanho e tente novamente.',
            $campo.'.max' => 'Arquivo maior que o limite de '.self::limiteMb().' MB.',
            $campo.'.extensions' => 'Extensão não aceita. Envie um destes formatos: '.self::extensoesLegiveis().'.',
        ];
    }

    /** Limite em MB, para exibir na interface. */
    public static function limiteMb(): int
    {
        return (int) (self::MAX_KB / 1024);
    }

    /** Lista de extensoes para texto de ajuda na interface. */
    public static function extensoesLegiveis(): string
    {
        return implode(', ', array_map(fn (string $e) => strtoupper($e), self::EXTENSOES));
    }

    /** Valor do atributo accept do <input type="file">. */
    public static function accept(): string
    {
        return implode(',', array_map(fn (string $e) => '.'.$e, self::EXTENSOES));
    }

    /** Disco unico usado para gravar e ler anexos. */
    public static function disco(): Filesystem
    {
        return Storage::disk(self::DISCO);
    }

    /**
     * Grava o arquivo na pasta informada e devolve o path relativo ao disco.
     *
     * @throws RuntimeException quando o storage recusa a gravacao (permissao, disco cheio).
     */
    public function guardar(UploadedFile $arquivo, string $pasta): string
    {
        $nome = Str::uuid()->toString().'_'.$this->nomeSeguro($arquivo);
        $path = $arquivo->storeAs($pasta, $nome, self::DISCO);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Falha ao gravar o anexo em [{$pasta}]. Verifique as permissões de storage/app/private.");
        }

        return $path;
    }

    /** Remove o arquivo do disco; ausencia nao e erro (anexo ja removido). */
    public function apagar(?string $path): void
    {
        if ($path) {
            self::disco()->delete($path);
        }
    }

    /** Nome original a partir do path gravado (o prefixo e o uuid). */
    public static function nomeOriginal(string $path): string
    {
        return Str::after(basename($path), '_');
    }

    /**
     * Sanitiza o nome original: remove separadores de diretorio, acentos e
     * caracteres que quebram header de download, e limita o comprimento para
     * que o path final caiba na coluna string(255).
     */
    private function nomeSeguro(UploadedFile $arquivo): string
    {
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $base = pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME);
        $base = Str::ascii($base);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? '';
        $base = trim($base, '-.');

        if ($base === '') {
            $base = 'anexo';
        }

        return Str::limit($base, self::MAX_NOME, '').($extensao !== '' ? '.'.$extensao : '');
    }
}
