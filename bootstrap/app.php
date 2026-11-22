<?php

use App\Http\Middleware\ActionThrottleRequests;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePanelRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\ErrorPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->trimStrings(except: ['definition.document.blocks.*.children.*.text', 'definition.document.blocks.*.items.*.*.text']);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('admin/content', 'admin/content/*')]);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['throttle' => ActionThrottleRequests::class, 'account.active' => EnsureAccountActive::class, 'panel.role' => EnsurePanelRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => app(ErrorPages::class)->respond($response, $exception, $request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
