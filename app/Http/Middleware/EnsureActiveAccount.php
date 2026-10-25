<?php

namespace App\Http\Middleware;

use App\Response\ResponseModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->status !== 'active' || ! $user->role()->where('status', 'active')->exists()) {
            $response = new ResponseModel(403, 'This account is not active!', null);

            return response()->json($response, $response->status);
        }

        return $next($request);
    }
}
