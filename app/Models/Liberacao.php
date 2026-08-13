<?php

namespace App\Models;

use App\Models\Concerns\TemUnidadeDeCliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Liberacao extends Model implements AuditableContract
{
    use Auditable, SoftDeletes, TemUnidadeDeCliente;

    protected $table = 'liberacoes';

    protected $fillable = [
        'numero_pi', 'numero_pc', 'cliente_id', 'unidade_id', 'escopo_id', 'data_pedido', 'nf_cliente',
        'prazo_entrega_dias', 'data_entrega_cliente', 'observacoes', 'criado_por',
    ];

    protected $casts = [
        'data_pedido' => 'date',
        'data_entrega_cliente' => 'date',
        'prazo_entrega_dias' => 'integer',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function escopo(): BelongsTo
    {
        return $this->belongsTo(Escopo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(LiberacaoItem::class);
    }

    /**
     * NFs proprias dos itens que divergem da NF do PI (itens sem NF herdam a do PI).
     *
     * @return array<int, string>
     */
    public function nfsDivergentes(): array
    {
        return $this->itens
            ->pluck('nf_cliente')
            ->filter(fn ($nf) => filled($nf) && $nf !== $this->nf_cliente)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resumo compacto da NF para a listagem: um rotulo curto + quantas NFs de item
     * divergem, para caber na coluna. Quando o PI nao tem NF, a primeira NF de item
     * vira o rotulo (senao a coluna mostraria "— +2", que nao informa nada).
     *
     * @return array{rotulo: ?string, extras: int, detalhe: ?string}
     */
    public function resumoNf(): array
    {
        $divergentes = $this->nfsDivergentes();
        $rotulo = filled($this->nf_cliente) ? $this->nf_cliente : ($divergentes[0] ?? null);
        $extras = count(array_filter($divergentes, fn ($nf) => $nf !== $rotulo));

        $detalhe = $extras > 0
            ? trim(sprintf(
                'NF do PI: %s · NF dos itens: %s',
                $this->nf_cliente ?: '—',
                implode(', ', $divergentes)
            ))
            : null;

        return ['rotulo' => $rotulo, 'extras' => $extras, 'detalhe' => $detalhe];
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(LiberacaoAnexo::class);
    }
}
