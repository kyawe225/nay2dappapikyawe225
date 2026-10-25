<?php

use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Support\Facades\Route;

test('administrator controllers are only exposed under the protected admin prefix', function (): void {
    $adminRoutes = collect(Route::getRoutes()->getRoutes())->filter(
        fn (Illuminate\Routing\Route $route): bool => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Admin\\')
    );

    expect($adminRoutes)->not->toBeEmpty();
    foreach ($adminRoutes as $route) {
        expect($route->uri())->toStartWith('api/admin/')
            ->and($route->gatherMiddleware())->toContain('auth:sanctum', EnsureAdministrator::class);
    }
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
