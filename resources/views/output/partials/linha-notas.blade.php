{{--
    Notas impressas sob a descricao da linha, iguais em todas as secoes do PI.

    - Especificacao do material: rotulo unico do Cadastro (categoria, dimensoes e norma).
      A secao MATERIA-PRIMA ja tem coluna propria para isso e passa $comMaterial = false.
    - Observacao livre da linha: instrucao do engenheiro para comprador e producao
      (ex.: "fazer aproveitamento junto com a chapa X").

    Parametros: $linha (EngenhariaLinha), $comMaterial (bool, padrao true).
    $alteracoes (MapaAlteracoes) vem herdado do pi.blade.php, unica view que usa esta partial.
--}}
@php
    $notaMaterial = ($comMaterial ?? true) ? $linha->material?->especificacao_completa : null;
    $materialAlterado = $alteracoes->campo($linha, 'material_id');
    $observacaoAlterada = $alteracoes->campo($linha, 'observacao');
@endphp

@if (filled($notaMaterial))
    <div class="nota-material {{ $materialAlterado ? 'alterado' : '' }}">
        {{ $notaMaterial }}
        @if ($materialAlterado)<span class="alterado-antes">(antes: {{ $materialAlterado['de'] }})</span>@endif
    </div>
@endif

@if (filled($linha->observacao))
    <div class="nota-obs"><span class="nota-rotulo">Obs.:</span>
        <span class="{{ $observacaoAlterada ? 'alterado' : '' }}">{{ $linha->observacao }}</span>
        @if ($observacaoAlterada)<span class="alterado-antes">(antes: {{ $observacaoAlterada['de'] }})</span>@endif
    </div>
@endif
