{{--
    Imprime um valor do PI destacando em vermelho o que mudou depois do ultimo PDF,
    com o valor anterior ao lado ("4  (antes: 3)").

    So o preview em tela recebe um mapa preenchido. O PDF que esta sendo gerado passa
    a ser a versao vigente do processo e sai sempre limpo — quem decide isso e o
    parametro $destacarAlteracoes de OutputService::montarDados().

    Parametros: $valor (string), $alteracao (array{de, para}|null vindo de MapaAlteracoes::campo).
--}}
@if ($alteracao)
    <span class="alterado">{{ $valor }}</span><span class="alterado-antes">(antes: {{ $alteracao['de'] }})</span>
@else
    {{ $valor }}
@endif
