<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => App\Http\Middleware\RequireRole::class, 'verified.email' => App\Http\Middleware\RequireVerifiedEmail::class]);
        $middleware->prependToGroup('api', App\Http\Middleware\AuthenticateFromCookie::class);
        // Token dari cookie harus sudah diterjemahkan sebelum auth:sanctum berjalan.
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, App\Http\Middleware\AuthenticateFromCookie::class);
        $middleware->appendToGroup('api', App\Http\Middleware\NormalizeDates::class);
        $middleware->appendToGroup('api', App\Http\Middleware\RequireHttps::class);
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, App\Http\Middleware\TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
