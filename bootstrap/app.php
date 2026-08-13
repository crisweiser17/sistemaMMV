<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ativo' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erro tem de voltar em JSON tambem para as chamadas fetch/XHR das telas
        // (window.mmvFetch envia Accept: application/json), e nao so para "api/*".
        // Sem o expectsJson() um upload invalido devolvia 302 + HTML: a mensagem real
        // de validacao nunca chegava ao front e o usuario so via o texto generico.
        // Formulario HTML normal (liberacao, cotacao) continua com redirect + withErrors.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
