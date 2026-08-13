<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use SoftDeletes;

    /** Separador dos blocos do rotulo: travessao cercado por espacos. */
    public const SEPARADOR = ' — ';

    protected $table = 'materiais';

    protected $fillable = ['tipo_id', 'descricao', 'dimensoes', 'norma', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoComponente::class, 'tipo_id');
    }

    /**
     * Rotulo completo do material, montado a partir de Categoria + Tipo + descricao +
     * dimensoes + norma: "Chapa de aco — 1.200 × 6.000 — ASTM A36".
     *
     * FONTE UNICA do rotulo: nenhuma view, controller ou service deve remontar essa
     * concatenacao. Assim o dado sai do Cadastro e chega a folha de processo sem redigitacao.
     */
    protected function especificacaoCompleta(): Attribute
    {
        return Attribute::get(fn (): string => self::juntar([
            $this->nomeDoTipo(),
            $this->dimensoes,
            $this->descricao,
            $this->norma,
        ]));
    }

    /** "Chapa" + categoria "Aco" => "Chapa de aco". Faltando uma das pontas, devolve a outra. */
    private function nomeDoTipo(): ?string
    {
        $tipo = trim((string) $this->tipo?->nome);
        $categoria = trim((string) $this->tipo?->categoria?->nome);

        if ($tipo === '' || $categoria === '') {
            return ($tipo !== '' ? $tipo : $categoria) ?: null;
        }

        // Tipo que ja cita a categoria ("Chapa de Aco") nao precisa repeti-la.
        if (str_contains(mb_strtolower($tipo), mb_strtolower($categoria))) {
            return $tipo;
        }

        return $tipo.' de '.mb_strtolower($categoria);
    }

    /**
     * Junta as partes preenchidas com o separador. Partes vazias e partes repetidas
     * (ex.: descricao igual a norma) somem sem deixar separador solto nem espaco duplo.
     */
    private static function juntar(array $partes): string
    {
        $unicas = [];

        foreach ($partes as $parte) {
            $texto = trim((string) $parte);

            if ($texto === '') {
                continue;
            }

            $unicas[mb_strtolower($texto)] ??= $texto;
        }

        return implode(self::SEPARADOR, $unicas);
    }
}
