<?php

namespace App\Http\Middleware;

use App\Services\SystemWriteLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SerializeSystemWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return app(SystemWriteLock::class)->run(fn () => $next($request));
    }
}
