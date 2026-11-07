<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryCorrectionController;
use App\Http\Controllers\Api\ManagerResourcesController;
use Closure;
use Illuminate\Http\Request;

/** Earlier acquisition workflows remain readable, but cannot create new stock or liabilities. */
class EnsureProductionWorkflow
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() && $this->retired($request)) {
            return response()->json([
                'message' => 'This earlier workflow is read-only. Purchase materials and tools, then use Production orders to receive carpets through washing.',
                'code' => 'workflow_read_only',
            ], 409);
        }

        return $next($request);
    }

    private function retired(Request $request): bool
    {
        $route = $request->route();
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();
        $resource = $route->parameter('resource');

        // Use matched route parameters: alternate URL encoding must not reopen a retired command.
        return match ($controller) {
            BusinessController::class => $method === 'command' && (in_array($resource, ['purchases', 'production', 'partnerships'], true)
                || ($resource === 'material-receipts' && ($route->parameter('action') ?? 'create') === 'create')),
            InventoryController::class => $method === 'store' && in_array($resource, ['carpets', 'purchases', 'receipts', 'payments'], true),
            InventoryCorrectionController::class => ! in_array($method, ['index', 'show'], true) && $resource !== 'adjustments',
            DirectoryController::class => $resource === 'weavers' && in_array($method, ['store', 'update', 'destroy'], true),
            ManagerResourcesController::class => $method === 'store' && $resource === 'tool-receipts',
            default => false,
        };
    }
}
