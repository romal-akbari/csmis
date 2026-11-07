<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a permission code, e.g.
 * ->middleware('permission:customers.view')
 */
class EnsurePermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $code,
    ): Response {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($code)) {
            return response()->json([
                'message' =>
                    'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
