<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS global: se ejecuta antes del routing y cubre respuestas de error
        // de Apache (OOM, timeout) donde el pipeline de Laravel no alcanza a correr.
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Resuelve la empresa actual desde el primer segmento de la URL para
        // el global scope de BelongsToEnterprise (ver retrofit de
        // multi-tenancy, docs/superpowers/specs/2026-08-23-agricultural-suite-multi-tenancy-design.md).
        $middleware->appendToGroup('api', \App\Http\Middleware\ResolveCurrentEnterprise::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
