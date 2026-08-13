<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Retrato imutavel das alteracoes feitas depois do ultimo PDF do PI, no formato que
 * o preview consulta: "este campo deste registro mudou, e antes valia X".
 *
 * Quem monta o mapa e o AlteracaoService; aqui so mora a consulta. O objeto e
 * construido uma vez e nunca alterado — a view le, nao escreve.
 */
final class MapaAlteracoes
{
    /**
     * @param  array<string, array<string, array{de: string, para: string}>>  $campos  chave do registro => campo => valores
     * @param  array<int, string>  $criados  chaves dos registros que nasceram depois do PDF
     * @param  array<int, string>  $excluidos  rotulos dos registros removidos depois do PDF
     * @param  int  $total  quantos eventos de auditoria entraram na conta
     */
    public function __construct(
        private readonly array $campos = [],
        private readonly array $criados = [],
        private readonly array $excluidos = [],
        private readonly ?CarbonInterface $marco = null,
        private readonly int $total = 0,
    ) {}

    /** Mapa neutro: nada destacado. E o que o PDF recebe. */
    public static function vazio(): self
    {
        return new self;
    }

    public function ativo(): bool
    {
        return $this->total > 0;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** Data do ultimo PDF: a altura de corte do que conta como alteracao. */
    public function marco(): ?CarbonInterface
    {
        return $this->marco;
    }

    /** @return array<int, string> */
    public function excluidos(): array
    {
        return $this->excluidos;
    }

    /**
     * Valores anterior e novo do campo, ou null quando ele nao mudou.
     *
     * @return array{de: string, para: string}|null
     */
    public function campo(?Model $registro, string $campo): ?array
    {
        if ($registro === null) {
            return null;
        }

        return $this->campos[self::chave($registro)][$campo] ?? null;
    }

    /** True quando o registro inteiro foi criado depois do ultimo PDF. */
    public function novo(?Model $registro): bool
    {
        return $registro !== null && in_array(self::chave($registro), $this->criados, true);
    }

    /** Identificador estavel do registro dentro do mapa. */
    public static function chave(Model $registro): string
    {
        return $registro::class.'#'.$registro->getKey();
    }
}
