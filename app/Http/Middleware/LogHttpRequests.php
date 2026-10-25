<?php

namespace App\Http\Middleware;

use App\Service\AuditDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogHttpRequests
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('http_request_id', (string) Str::uuid7());
        $request->attributes->set('http_request_started_at', hrtime(true));
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('http_request_id'));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->has('http_request_started_at') || $request->attributes->get('http_request_logged', false)) {
            return;
        }
        $request->attributes->set('http_request_logged', true);
        try {
            $user = $request->user();
        } catch (Throwable) {
            $user = null;
        }
        $administratorEndpoint = $request->is('api/admin', 'api/admin/*');
        $context = [
            'request_id' => $request->attributes->get('http_request_id'),
            'category' => $administratorEndpoint ? 'admin' : ($request->is('api/*') ? 'customer_api' : 'web'),
            'user_id' => $user?->id, 'actor_role_id' => $user?->role_id,
            'method' => substr($request->method(), 0, 10),
            'path' => substr('/'.ltrim($request->path(), '/'), 0, 1024),
            'route' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => max(0, (int) round((hrtime(true) - $request->attributes->get('http_request_started_at')) / 1000000)),
            'ip_address' => $request->ip(),
        ];
        $level = $response->getStatusCode() >= 500 ? 'error' : ($response->getStatusCode() >= 400 ? 'warning' : 'info');
        $this->WriteLog($level, 'HTTP request completed', $context);
        if ($administratorEndpoint && $user !== null && $user->isAdministrator()) {
            try {
                app(AuditDatabase::class)->Connection()->table('admin_api_audits')->insert([
                    'id' => (string) Str::uuid7(), 'request_id' => $context['request_id'],
                    'user_id' => $context['user_id'], 'actor_role_id' => $context['actor_role_id'],
                    'method' => $context['method'], 'path' => $context['path'], 'route' => $context['route'],
                    'status_code' => $context['status_code'], 'duration_ms' => $context['duration_ms'],
                    'ip_address' => $context['ip_address'], 'created_at' => now('UTC'),
                ]);
            } catch (Throwable $exception) {
                $this->WriteLog('error', 'Admin API audit persistence failed', [...$context, 'exception_type' => $exception::class]);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function WriteLog(string $level, string $message, array $context): void
    {
        try {
            Log::channel('http')->log($level, $message, $context);
        } catch (Throwable) {
            error_log($message.' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
