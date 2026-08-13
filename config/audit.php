<?php

/**
 * Sobrescreve apenas o que o MMV precisa do owen-it/laravel-auditing. O restante das
 * chaves continua vindo do config padrao do pacote (o provider faz merge raso, entao
 * um arquivo parcial nao apaga nada).
 */
return [
    /*
    | Auditoria fora do HTTP (artisan, seeders, testes).
    |
    | O pacote desliga a auditoria em console por padrao. A marcacao de alteracao
    | pos-liberacao (AlteracaoService) le exatamente essa auditoria, entao a suite
    | de testes precisa dela ligada — ver AUDIT_CONSOLE no phpunit.xml. Em producao
    | o padrao continua desligado: seeder nao e mudanca de processo.
    */
    'console' => env('AUDIT_CONSOLE', false),
];
