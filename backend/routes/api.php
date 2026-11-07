<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\FoundationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryCorrectionController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\ManagerProductionController;
use App\Http\Controllers\Api\ManagerResourcesController;
use App\Http\Controllers\Api\ManagerWorkflowController;
use App\Http\Controllers\Api\SystemResetController;
use App\Http\Controllers\Api\SupplierBalanceController;
use App\Http\Controllers\Api\WashingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (§24) — JSON only, stateless Bearer-token auth via Sanctum
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/branding/images/{slot}/{version}', [BrandingController::class, 'image']);

Route::middleware(['auth:sanctum', 'active', 'production-workflow'])->group(function () {
    Route::get('/branding', [BrandingController::class, 'index']);
    Route::get('/system/reset-preview', [SystemResetController::class, 'preview'])
        ->middleware('throttle:system-reset-preview');
    Route::post('/system/reset', [SystemResetController::class, 'store'])
        ->middleware('throttle:system-reset');
    Route::get('/dashboard/overview', DashboardController::class);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::prefix('production')->group(function () {
        Route::get('/supplier-balances', [SupplierBalanceController::class, 'index']);
        Route::get('/suppliers/{id}/statement', [SupplierBalanceController::class, 'show'])->whereNumber('id');
        Route::post('/setup', [ManagerWorkflowController::class, 'command'])->defaults('action', 'setup');
        Route::get('/supply-purchases', [ManagerWorkflowController::class, 'supplies']);
        Route::post('/supply-purchases/{id}/pay', [ManagerWorkflowController::class, 'command'])->whereNumber('id')->defaults('action', 'supply-pay');
        Route::post('/supply-purchases', [ManagerWorkflowController::class, 'command'])->defaults('action', 'supply');
        Route::get('/orders/{id}/workflow', [ManagerWorkflowController::class, 'context'])->whereNumber('id');
        foreach (['issue', 'receive', 'accept', 'settle'] as $action) {
            Route::post('/orders/{id}/'.$action, [ManagerWorkflowController::class, 'command'])->whereNumber('id')->defaults('action', $action);
        }
        Route::post('/managers/{id}/assistance', [ManagerWorkflowController::class, 'command'])->whereNumber('id')->defaults('action', 'assistance');
        Route::get('/lookups', [ManagerProductionController::class, 'lookups']);
        Route::get('/orders', [ManagerProductionController::class, 'index']);
        Route::post('/orders', [ManagerProductionController::class, 'command'])->defaults('action', 'order');
        Route::get('/orders/{id}', [ManagerProductionController::class, 'show'])->whereNumber('id');
        Route::post('/orders/{id}/preview', [ManagerProductionController::class, 'preview'])->whereNumber('id');
        Route::post('/orders/{id}/deliveries', [ManagerProductionController::class, 'command'])->whereNumber('id')->defaults('action', 'delivery');
        Route::post('/orders/{id}/pay', [ManagerProductionController::class, 'command'])->whereNumber('id')->defaults('action', 'pay');
        Route::post('/orders/{id}/close', [ManagerProductionController::class, 'command'])->whereNumber('id')->defaults('action', 'close');
        Route::get('/managers/{id}/statement', [ManagerResourcesController::class, 'statement'])->whereNumber('id');
        Route::post('/managers/{id}/materials', [ManagerResourcesController::class, 'managerMaterial'])->whereNumber('id');
        Route::post('/managers/{id}/tools', [ManagerResourcesController::class, 'managerTool'])->whereNumber('id');
        Route::get('/{resource}', [ManagerResourcesController::class, 'index'])->whereIn('resource', ['managers', 'tools', 'tool-receipts', 'assistance']);
        Route::post('/{resource}', [ManagerResourcesController::class, 'store'])->whereIn('resource', ['managers', 'tools', 'tool-receipts', 'assistance']);
        Route::patch('/{resource}/{id}', [ManagerResourcesController::class, 'update'])->whereIn('resource', ['managers', 'tools'])->whereNumber('id');
        Route::get('/{resource}/{id}', [ManagerResourcesController::class, 'show'])->whereIn('resource', ['managers', 'tools', 'tool-receipts', 'assistance'])->whereNumber('id');
        Route::post('/{resource}/{id}/{action}', [ManagerResourcesController::class, 'action'])->whereIn('resource', ['managers', 'tools', 'tool-receipts', 'assistance'])->whereNumber('id');
    });
    Route::prefix('business')->group(function () {
        Route::get('/lookups', [BusinessController::class, 'lookups']);
        Route::get('/weavers/{id}/statement', [BusinessController::class, 'weaver'])->whereNumber('id');
        Route::post('/payments/{id}/reverse', [BusinessController::class, 'reverse'])->whereNumber('id');
        Route::post('/expenses/{id}/attachments', [BusinessController::class, 'attachment'])->whereNumber('id');
        Route::get('/expenses/{id}/attachments/{attachment}', [BusinessController::class, 'download'])->whereNumber(['id', 'attachment']);
        Route::get('/{resource}', [BusinessController::class, 'index']);
        Route::get('/{resource}/{id}', [BusinessController::class, 'show'])->whereNumber('id');
        Route::post('/{resource}', [BusinessController::class, 'command']);
        Route::post('/{resource}/{id}/{action}', [BusinessController::class, 'command'])->whereNumber('id');
    });
    Route::patch('/profile/locale', [AuthController::class, 'locale']);
    Route::prefix('washing')->group(function () {
        Route::get('/lookups', [WashingController::class, 'lookups']);
        Route::get('/eligible-carpets', [WashingController::class, 'eligible']);
        Route::get('/orders', [WashingController::class, 'orders']);
        Route::get('/orders/{id}', [WashingController::class, 'order'])->whereNumber('id');
        Route::get('/stock', [WashingController::class, 'stock']);
        Route::get('/visits', [WashingController::class, 'visits']);
        Route::get('/bills', [WashingController::class, 'bills']);
        Route::get('/bills/{id}', [WashingController::class, 'bill'])->whereNumber('id');
        Route::post('/dispatch', [WashingController::class, 'command'])->defaults('operation', 'dispatch');
        Route::post('/orders/{id}/complete', [WashingController::class, 'command'])->whereNumber('id')->defaults('operation', 'complete');
        Route::post('/orders/{id}/return', [WashingController::class, 'command'])->whereNumber('id')->defaults('operation', 'return');
        Route::post('/bills', [WashingController::class, 'command'])->defaults('operation', 'bill');
        Route::post('/bills/{id}/payments', [WashingController::class, 'command'])->whereNumber('id')->defaults('operation', 'payment');
        Route::post('/bills/{id}/void', [WashingController::class, 'command'])->whereNumber('id')->defaults('operation', 'void');
        Route::post('/payments/{id}/reverse', [WashingController::class, 'command'])->whereNumber('id')->defaults('operation', 'reverse');
    });
    Route::prefix('inventory')->group(function () {
        Route::get('/stock-overview', [\App\Http\Controllers\Api\StockOverviewController::class, 'index']);
        Route::get('/stock-overview/{id}', [\App\Http\Controllers\Api\StockOverviewController::class, 'show'])->whereNumber('id');
        Route::post('/purchases/{id}/void', [InventoryCorrectionController::class, 'voidPurchase'])->whereNumber('id');
        Route::prefix('corrections')->group(function () {
            Route::put('/drafts/{id}', [InventoryCorrectionController::class, 'updateDraft'])->whereNumber('id');
            Route::post('/drafts/{id}/{action}', [InventoryCorrectionController::class, 'finalizeDraft'])->whereNumber('id');
            Route::get('/{resource}', [InventoryCorrectionController::class, 'index']);
            Route::get('/{resource}/{id}', [InventoryCorrectionController::class, 'show'])->whereNumber('id');
            Route::post('/{resource}', [InventoryCorrectionController::class, 'store']);
        });
        Route::get('/lookups', [InventoryController::class, 'lookups']);
        Route::put('/carpets/{id}', [InventoryController::class, 'updateCarpet'])->whereNumber('id');
        Route::post('/carpets/{id}/images', [InventoryController::class, 'upload'])->whereNumber('id');
        Route::get('/carpets/{id}/images/{imageId}', [InventoryController::class, 'image'])->whereNumber(['id', 'imageId']);
        Route::delete('/carpets/{id}/images/{imageId}', [InventoryController::class, 'deleteImage'])->whereNumber(['id', 'imageId']);
        Route::get('/{resource}', [InventoryController::class, 'index']);
        Route::get('/{resource}/{id}', [InventoryController::class, 'show'])->whereNumber('id');
        Route::post('/{resource}', [InventoryController::class, 'store']);
    });
    Route::prefix('directories')->group(function () {
        Route::get('/lookups', [DirectoryController::class, 'lookups']);
        Route::get('/{resource}', [DirectoryController::class, 'index']);
        Route::get('/{resource}/{id}', [DirectoryController::class, 'show'])->whereNumber('id');
        Route::post('/{resource}', [DirectoryController::class, 'store']);
        Route::put('/{resource}/{id}', [DirectoryController::class, 'update'])->whereNumber('id');
        Route::delete('/{resource}/{id}', [DirectoryController::class, 'destroy'])->whereNumber('id');
    });
    Route::prefix('foundation')->group(function () {
        Route::post('/branding/{slot}', [BrandingController::class, 'store']);
        Route::delete('/branding/{slot}', [BrandingController::class, 'destroy']);
        Route::get('/lookups', [FoundationController::class, 'lookups']);
        Route::get('/settings', [FoundationController::class, 'settings']);
        Route::put('/settings', [FoundationController::class, 'updateSettings']);
        Route::get('/audit', [FoundationController::class, 'audit']);
        Route::get('/ledger', [LedgerController::class, 'index']);
        Route::post('/ledger', [LedgerController::class, 'store']);
        Route::post('/ledger/{id}/reverse', [LedgerController::class, 'reverse'])->whereNumber('id');
        Route::get('/{resource}', [FoundationController::class, 'index']);
        Route::post('/{resource}', [FoundationController::class, 'store']);
        Route::put('/{resource}/{id}', [FoundationController::class, 'update'])->whereNumber('id');
    });

    // §17 — each customer action needs its own permission.
    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.view');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.update');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.update');
    Route::delete(
        '/customers/{customer}',
        [CustomerController::class, 'destroy'],
    )->middleware('permission:customers.delete');
    Route::post('/customers/{id}/restore', [CustomerController::class, 'restore'])
        ->whereNumber('id')->middleware('permission:customers.update');
});
