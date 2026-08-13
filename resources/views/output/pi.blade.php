<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>PI {{ $numeroReferencia }}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 13.5px; color: #222; margin: 0; padding: 24px; line-height: 1.5; }
    h1 { font-size: 20px; margin: 0 0 3px; color: #1E1E1E; }
    .sub { color: #666; font-size: 12px; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    /* word-wrap: texto longo (observacao livre, especificacao de material) quebra dentro
       da celula em vez de esticar a tabela para fora da pagina no dompdf. */
    th, td { border: 1px solid #ccc; padding: 7px 8px; text-align: left; vertical-align: top; word-wrap: break-word; }
    th { background: #f3f3f2; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
    .meta td { border: none; padding: 3px 10px 3px 0; font-size: 13.5px; }
    .meta .label { color: #888; width: 140px; }
    .item-titulo { background: #1E1E1E; color: #fff; padding: 9px 12px; font-size: 15px; font-weight: bold; margin: 22px 0 0; border-radius: 3px 3px 0 0; }
    .item-meta { border: 1px solid #ccc; border-top: none; padding: 8px 12px; font-size: 12.5px; color: #555; margin-bottom: 4px; }
    .secao { background: #404040; color: #fff; padding: 6px 10px; font-weight: bold; font-size: 12.5px; margin: 12px 0 0; }
    .vazio { color: #999; font-style: italic; padding: 7px; font-size: 13px; }
    .item-bloco { page-break-inside: avoid; }
    .quebra { page-break-before: always; }
    /* Notas sob a descricao da linha, iguais em todas as secoes. */
    .nota-material { font-size: 11.5px; color: #444; margin-top: 3px; }
    .nota-obs { font-size: 11.5px; color: #6b3d00; background: #fff6e8; border-left: 3px solid #EF8332; padding: 3px 6px; margin-top: 4px; }
    .nota-rotulo { font-weight: bold; }
    /* Alteracao depois do ultimo PDF: so aparece no preview em tela. */
    .alterado { color: #C0201F; font-weight: bold; }
    .alterado-antes { color: #C0201F; font-size: 10.5px; font-weight: normal; margin-left: 4px; }
    .badge-nova { background: #C0201F; color: #fff; font-size: 9.5px; font-weight: bold; padding: 1px 5px; border-radius: 2px; margin-right: 5px; letter-spacing: .04em; }
    .aviso-alteracao { border: 1px solid #C0201F; background: #FDECEC; color: #8A1414; padding: 9px 11px; margin-bottom: 14px; border-radius: 3px; font-size: 12.5px; }
    .aviso-alteracao .titulo { font-weight: bold; text-transform: uppercase; letter-spacing: .04em; }
</style>
</head>
<body>
    <h1>PROCESSO DE PRODUÇÃO — PI {{ $numeroReferencia }}</h1>
    <div class="sub">Gerado em {{ $gerado_em->format('d/m/Y H:i') }} · {{ $itens->count() }} {{ $itens->count() === 1 ? 'item' : 'itens' }}</div>

    {{-- Aviso de alteracao pos-liberacao. Presente so no preview: o mapa que chega ao
         PDF e sempre vazio (OutputService::montarDados). --}}
    @if ($alteracoes->ativo())
        <div class="aviso-alteracao">
            <span class="titulo">Processo alterado após a liberação</span> —
            {{ $alteracoes->total() }} {{ $alteracoes->total() === 1 ? 'alteração registrada' : 'alterações registradas' }}
            desde o último PDF, gerado em {{ $alteracoes->marco()?->format('d/m/Y H:i') }}.
            Os valores alterados aparecem em vermelho, com o valor anterior ao lado.
            @if ($alteracoes->excluidos())
                <br>Removidos após o PDF: {{ implode(' · ', $alteracoes->excluidos()) }}.
            @endif
        </div>
    @endif

    {{-- Cabecalho da demanda/referencia (comum a todos os itens) --}}
    <table class="meta">
        <tr>
            <td class="label">Cliente</td><td>{{ $clienteRotulo ?? '—' }}</td>
            <td class="label">PC / Referência</td><td>{{ $referencia->numero_pc ?? $referencia->numero ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">NF Cliente</td>
            <td>@include('output.partials.valor-alterado', [
                'valor' => $referencia->nf_cliente ?? '—',
                'alteracao' => $alteracoes->campo($referencia, 'nf_cliente'),
            ])</td>
            <td class="label">Prazo entrega</td>
            <td>@include('output.partials.valor-alterado', [
                'valor' => ($referencia->prazo_entrega_dias ?? '—').' dias',
                'alteracao' => $alteracoes->campo($referencia, 'prazo_entrega_dias'),
            ])</td>
        </tr>
        <tr>
            <td class="label">Observações</td>
            <td colspan="3">@include('output.partials.valor-alterado', [
                'valor' => $referencia->observacoes ?? '—',
                'alteracao' => $alteracoes->campo($referencia, 'observacoes'),
            ])</td>
        </tr>
    </table>

    {{-- Um grupo de detalhamento por item (header) --}}
    @foreach ($itens as $i => $item)
        @php
            $h = $item['header'];
            // NF efetiva do item; so vale a pena imprimir quando difere da NF do cabecalho.
            $nfItem = $h->dadosItemOrigem()['nf'];
            $nfDivergente = filled($nfItem) && $nfItem !== ($referencia->nf_cliente ?? null);
        @endphp
        <div class="item-bloco {{ $i > 0 ? 'quebra' : '' }}">
            <div class="item-titulo">
                Item {{ $i + 1 }} — {{ $h->nome_item ?? '—' }}
                @if ($alteracoes->campo($h, 'nome_item'))
                    <span class="alterado-antes">(antes: {{ $alteracoes->campo($h, 'nome_item')['de'] }})</span>
                @endif
            </div>
            <div class="item-meta">
                Responsável: {{ $h->responsavel?->name ?? '—' }}
                · Status: {{ $h->status?->nome ?? '—' }}
                · Data alocação: {{ optional($h->data_alocacao)->format('d/m/Y') ?? '—' }}
                @if ($nfDivergente) · NF do item: {{ $nfItem }} @endif
            </div>

            {{-- MATERIA-PRIMA --}}
            <div class="secao">MATÉRIA-PRIMA</div>
            @if ($item['materia_prima']->count())
                <table>
                    <thead><tr><th style="width:60px">Qtd</th><th>Descrição</th><th style="width:210px">Material / Norma</th></tr></thead>
                    <tbody>
                        @foreach ($item['materia_prima'] as $l)
                            <tr>
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => rtrim(rtrim((string) $l->quantidade, '0'), '.'),
                                    'alteracao' => $alteracoes->campo($l, 'quantidade'),
                                ])</td>
                                <td>
                                    @if ($alteracoes->novo($l))<span class="badge-nova">NOVA</span>@endif
                                    @include('output.partials.valor-alterado', [
                                        'valor' => $l->descricao,
                                        'alteracao' => $alteracoes->campo($l, 'descricao'),
                                    ])
                                    {{-- comMaterial=false: aqui o material ja tem coluna propria. --}}
                                    @include('output.partials.linha-notas', ['linha' => $l, 'comMaterial' => false])
                                </td>
                                {{-- Especificacao completa vinda do Cadastro: categoria, dimensoes e norma. --}}
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => $l->material?->especificacao_completa,
                                    'alteracao' => $alteracoes->campo($l, 'material_id'),
                                ])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else <div class="vazio">Sem itens de matéria-prima.</div> @endif

            {{-- MAO DE OBRA --}}
            <div class="secao">MÃO DE OBRA</div>
            @if ($item['mao_de_obra']->count())
                <table>
                    <thead><tr><th style="width:60px">Qtd</th><th>Descrição</th><th style="width:200px">Mão de obra / Pedido usinagem</th></tr></thead>
                    <tbody>
                        @foreach ($item['mao_de_obra'] as $l)
                            <tr>
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => rtrim(rtrim((string) $l->quantidade, '0'), '.'),
                                    'alteracao' => $alteracoes->campo($l, 'quantidade'),
                                ])</td>
                                <td>
                                    @if ($alteracoes->novo($l))<span class="badge-nova">NOVA</span>@endif
                                    @include('output.partials.valor-alterado', [
                                        'valor' => $l->descricao,
                                        'alteracao' => $alteracoes->campo($l, 'descricao'),
                                    ])
                                    @include('output.partials.linha-notas', ['linha' => $l])
                                </td>
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => $l->mao_de_obra,
                                    'alteracao' => $alteracoes->campo($l, 'mao_de_obra'),
                                ])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else <div class="vazio">Sem itens de mão de obra.</div> @endif

            {{-- COMPONENTES COMERCIAIS --}}
            <div class="secao">COMPONENTES COMERCIAIS</div>
            @if ($item['comerciais']->count())
                <table>
                    <thead><tr><th style="width:60px">Qtd</th><th>Especificação</th></tr></thead>
                    <tbody>
                        @foreach ($item['comerciais'] as $l)
                            <tr>
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => rtrim(rtrim((string) $l->quantidade, '0'), '.'),
                                    'alteracao' => $alteracoes->campo($l, 'quantidade'),
                                ])</td>
                                <td>
                                    @if ($alteracoes->novo($l))<span class="badge-nova">NOVA</span>@endif
                                    @include('output.partials.valor-alterado', [
                                        'valor' => $l->descricao,
                                        'alteracao' => $alteracoes->campo($l, 'descricao'),
                                    ])
                                    @include('output.partials.linha-notas', ['linha' => $l])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else <div class="vazio">Sem componentes comerciais.</div> @endif

            {{-- INSUMOS --}}
            <div class="secao">INSUMOS</div>
            @if ($item['insumos']->count())
                <table>
                    {{-- Sem coluna "Observação" propria: a observacao da linha e impressa sob a
                         descricao, no mesmo formato das demais secoes. --}}
                    <thead><tr><th style="width:60px">Qtd</th><th>Descrição</th></tr></thead>
                    <tbody>
                        @foreach ($item['insumos'] as $l)
                            <tr>
                                <td>@include('output.partials.valor-alterado', [
                                    'valor' => rtrim(rtrim((string) $l->quantidade, '0'), '.'),
                                    'alteracao' => $alteracoes->campo($l, 'quantidade'),
                                ])</td>
                                <td>
                                    @if ($alteracoes->novo($l))<span class="badge-nova">NOVA</span>@endif
                                    @include('output.partials.valor-alterado', [
                                        'valor' => $l->descricao,
                                        'alteracao' => $alteracoes->campo($l, 'descricao'),
                                    ])
                                    @include('output.partials.linha-notas', ['linha' => $l])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else <div class="vazio">Sem insumos (ex.: Gelo Seco, Isopor).</div> @endif
        </div>
    @endforeach

    @if ($itens->isEmpty())
        <div class="vazio">Esta demanda ainda não tem itens detalhados.</div>
    @endif
</body>
</html>
