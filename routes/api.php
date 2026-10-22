<?php

use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\WorkflowIntegrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - EcoLoop Agent v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Inventory Automation Endpoints
    Route::prefix('inventory')->group(function () {
        Route::get('expiring-soon', [InventoryApiController::class, 'expiringSoon'])->name('api.v1.inventory.expiring-soon');
        Route::post('apply-discount', [InventoryApiController::class, 'applyDiscount'])->name('api.v1.inventory.apply-discount');
        Route::post('reserve-donation', [InventoryApiController::class, 'reserveDonation'])->name('api.v1.inventory.reserve-donation');
    });

    Route::get('products/{product}', [InventoryApiController::class, 'showProduct'])
        ->whereNumber('product')
        ->name('api.v1.products.show');

    Route::prefix('agent-logs')->group(function () {
        Route::post('summary', [WorkflowIntegrationController::class, 'storeSummary'])
            ->name('api.v1.agent-logs.summary');
        Route::post('error', [WorkflowIntegrationController::class, 'storeError'])
            ->name('api.v1.agent-logs.error');
    });

    // AI & n8n Integration / Mock Fallback Endpoints
    Route::prefix('ai')->group(function () {
        Route::get('status', [InventoryApiController::class, 'aiStatus'])->name('api.v1.ai.status');
        Route::post('evaluate', [InventoryApiController::class, 'evaluateProduct'])->name('api.v1.ai.evaluate');
        Route::post('simulate-run', [InventoryApiController::class, 'simulateAiRun'])->name('api.v1.ai.simulate-run');
        Route::post('webhook', [InventoryApiController::class, 'handleWebhook'])->name('api.v1.ai.webhook');
    });
});
