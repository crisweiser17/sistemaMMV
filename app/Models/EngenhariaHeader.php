<?php

namespace App\Models;

use App\Models\Concerns\TemUnidadeDeCliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EngenhariaHeader extends Model implements AuditableContract
{
    use Auditable, SoftDeletes, TemUnidadeDeCliente;

    protected $table = 'engenharia_headers';

    protected $fillable = [
        'demanda_id', 'responsavel_id', 'cliente_id', 'unidade_id', 'numero_referencia',
        'nome_item', 'data_alocacao', 'status_id', 'liberacao_item_id', 'cotacao_item_id',
    ];

    protected $casts = ['data_alocacao' => 'date'];

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusEngenharia::class, 'status_id');
    }

    /** Item da cotacao de origem (itens removidos ainda aparecem no detalhamento). */
    public function itemCotacao(): BelongsTo
    {
        return $this->belongsTo(CotacaoItem::class, 'cotacao_item_id')->withTrashed();
    }

    /** Item do PI (liberacao) de origem (itens removidos ainda aparecem no detalhamento). */
    public function itemLiberacao(): BelongsTo
    {
        return $this->belongsTo(LiberacaoItem::class, 'liberacao_item_id')->withTrashed();
    }

    /**
     * Item que originou o header: PI (liberacao) ou cotacao, conforme o preenchido
     * na alocacao. Evita que a tela precise saber o tipo da demanda.
     */
    public function itemOrigem(): CotacaoItem|LiberacaoItem|null
    {
        return $this->itemLiberacao ?? $this->itemCotacao;
    }

    /**
     * Shape unico do cabecalho do item, valido para PI e cotacao — as duas tabelas
     * de item compartilham os mesmos campos de negocio.
     *
     * @return array<string, mixed>
     */
    public function dadosItemOrigem(): array
    {
        $item = $this->itemOrigem();

        return [
            'cod_mmv' => $item?->cod_mmv,
            'ni' => $item?->ni,
            'descricao' => $item?->descricao,
            'quantidade' => $item?->quantidade,
            'unidade' => $item?->unidade?->sigla,
            'material_cliente' => $item?->material_cliente,
            // NF efetiva do item de PI (propria ou herdada do PI). Cotacao nao tem NF por item.
            'nf' => $item instanceof LiberacaoItem ? $item->nf_efetiva : null,
            'descricao_cliente' => $item?->descricao_cliente,
            'observacoes' => $item?->observacoes,
        ];
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(EngenhariaLinha::class, 'header_id')->orderBy('numero_linha');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class, 'header_id')->latest('gerado_em');
    }
}
