<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `using` replaces the default web/api registration, so all three files are listed here.
        // routes/ai.php holds the MCP server for agents (docs/mcp.md).
        using: function (): void {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
            require base_path('routes/ai.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum ships these but Laravel does not alias them; the MCP routes and any token-scoped
        // API route need `abilities` to keep a drafting token from being able to publish.
        $middleware->alias([
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        // There is no separate app login: the Filament panel is the only way in.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));

        // Meta signs these callbacks with signed_request and has no CSRF token to send.
        $middleware->validateCsrfTokens(except: [
            'meta/deauthorize',
            'meta/data-deletion',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // MCP clients speak JSON-RPC and must never be handed an HTML login page, even when they
        // forget the Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp*') || $request->expectsJson(),
        );
    })->create();
