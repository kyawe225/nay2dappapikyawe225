<?php

use App\Http\Middleware\ApiMiddleware;
use App\Http\Middleware\LogHttpRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LogHttpRequests::class);
        $middleware->api(prepend: [ApiMiddleware::class]);
        $middleware->alias(['abilities' => CheckAbilities::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(fn (): array => [
            'request_id' => app()->bound('request') ? request()->attributes->get('http_request_id') : null,
        ]);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->attributes->has('http_request_id')) {
                $response->headers->set('X-Request-ID', $request->attributes->get('http_request_id'));
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
