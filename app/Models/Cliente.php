<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Cliente extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    /** Separador do rotulo de exibicao (travessao com espacos). */
    public const SEPARADOR_UNIDADE = ' – ';

    // A coluna legada 'unidade' esta obsoleta desde a criacao de cliente_unidades;
    // permanece na tabela apenas por compatibilidade e nao e mais lida pelo sistema.
    protected $fillable = ['nome', 'codigo_pa', 'unidade', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function unidades(): HasMany
    {
        return $this->hasMany(ClienteUnidade::class);
    }

    /**
     * Fonte unica do formato "Cliente – Unidade". Sem unidade devolve so o nome
     * do cliente (nunca sobra travessao); sem cliente devolve null.
     */
    public static function rotulo(?string $cliente, ?string $unidade): ?string
    {
        $cliente = trim((string) $cliente);
        $unidade = trim((string) $unidade);

        if ($cliente === '') {
            return null;
        }

        return $unidade === '' ? $cliente : $cliente.self::SEPARADOR_UNIDADE.$unidade;
    }
}
