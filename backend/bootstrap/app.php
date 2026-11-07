<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureProductionWorkflow;
use App\Http\Middleware\SerializeSystemWrites;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SerializeSystemWrites::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SerializeSystemWrites::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'permission' => EnsurePermission::class,
            'production-workflow' => EnsureProductionWorkflow::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $error,
            Request $request) {
            if ($request->is('api/*') && $error->getCode() === '23000') {
                return response()->json([
                    'message' => 'This change conflicts with an existing record. Refresh and try again.',
                ], 409);
            }

            return null;
        });
    })->create();
