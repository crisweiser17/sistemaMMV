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
    th, td { border: 1px solid #ccc; padding: 7px 8px; text-align: left; vertical-align: top; }
    th { background: #f3f3f2; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
    .meta td { border: none; padding: 3px 10px 3px 0; font-size: 13.5px; }
    .meta .label { color: #888; width: 140px; }
    .item-titulo { background: #1E1E1E; color: #fff; padding: 9px 12px; font-size: 15px; font-weight: bold; margin: 22px 0 0; border-radius: 3px 3px 0 0; }
    .item-meta { border: 1px solid #ccc; border-top: none; padding: 8px 12px; font-size: 12.5px; color: #555; margin-bottom: 4px; }
    .secao { background: #404040; color: #fff; padding: 6px 10px; font-weight: bold; font-size: 12.5px; margin: 12px 0 0; }
    .vazio { color: #999; font-style: italic; padding: 7px; font-size: 13px; }
    .item-bloco { page-break-inside: avoid; }
    .quebra { page-break-before: always; }
</style>
</head>
<body>
    <h1>PROCESSO DE PRODUÇÃO — PI {{ $numeroReferencia }}</h1>
    <div class="sub">Gerado em {{ $gerado_em->format('d/m/Y H:i') }} · {{ $itens->count() }} {{ $itens->count() === 1 ? 'item' : 'itens' }}</div>

    {{-- Cabecalho da demanda/referencia (comum a todos os itens) --}}
    <table class="meta">
        <tr>
            <td class="label">Cliente</td><td>{{ $cliente?->nome ?? '—' }}</td>
            <td class="label">PC / Referência</td><td>{{ $referencia->numero_pc ?? $referencia->numero ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">NF Cliente</td><td>{{ $referencia->nf_cliente ?? '—' }}</td>
            <td class="label">Prazo entrega</td><td>{{ $referencia->prazo_entrega_dias ?? '—' }} dias</td>
        </tr>
        <tr>
            <td class="label">Observações</td><td colspan="3">{{ $referencia->observacoes ?? '—' }}</td>
        </tr>
    </table>

    {{-- Um grupo de detalhamento por item (header) --}}
    @foreach ($itens as $i => $item)
        @php $h = $item['header']; @endphp
        <div class="item-bloco {{ $i > 0 ? 'quebra' : '' }}">
            <div class="item-titulo">Item {{ $i + 1 }} — {{ $h->nome_item ?? '—' }}</div>
            <div class="item-meta">
                Responsável: {{ $h->responsavel?->name ?? '—' }}
                · Status: {{ $h->status?->nome ?? '—' }}
                · Data alocação: {{ optional($h->data_alocacao)->format('d/m/Y') ?? '—' }}
            </div>

            {{-- MATERIA-PRIMA --}}
            <div class="secao">MATÉRIA-PRIMA</div>
            @if ($item['materia_prima']->count())
                <table>
                    <thead><tr><th style="width:60px">Qtd</th><th>Descrição</th><th style="width:160px">Material / Norma</th></tr></thead>
                    <tbody>
                        @foreach ($item['materia_prima'] as $l)
                            <tr><td>{{ rtrim(rtrim((string)$l->quantidade,'0'),'.') }}</td><td>{{ $l->descricao }}</td>
                            <td>{{ $l->material?->descricao }}{{ $l->material?->norma ? ' · '.$l->material->norma : '' }}</td></tr>
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
                            <tr><td>{{ rtrim(rtrim((string)$l->quantidade,'0'),'.') }}</td><td>{{ $l->descricao }}</td><td>{{ $l->mao_de_obra }}</td></tr>
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
                            <tr><td>{{ rtrim(rtrim((string)$l->quantidade,'0'),'.') }}</td><td>{{ $l->descricao }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else <div class="vazio">Sem componentes comerciais.</div> @endif

            {{-- INSUMOS --}}
            <div class="secao">INSUMOS</div>
            @if ($item['insumos']->count())
                <table>
                    <thead><tr><th style="width:60px">Qtd</th><th>Descrição</th><th>Observação</th></tr></thead>
                    <tbody>
                        @foreach ($item['insumos'] as $l)
                            <tr><td>{{ rtrim(rtrim((string)$l->quantidade,'0'),'.') }}</td><td>{{ $l->descricao }}</td><td>{{ $l->observacao }}</td></tr>
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
