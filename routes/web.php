<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [DashboardController::class, 'index'])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Product Inventory Management CRUD & Manual Overrides
    Route::get('inventory', [ProductController::class, 'index'])->name('inventory.index');
    Route::get('inventory/create', [ProductController::class, 'create'])->name('inventory.create');
    Route::post('inventory', [ProductController::class, 'store'])->name('inventory.store');
    Route::get('inventory/{product}/edit', [ProductController::class, 'edit'])->name('inventory.edit');
    Route::put('inventory/{product}', [ProductController::class, 'update'])->name('inventory.update');
    Route::delete('inventory/{product}', [ProductController::class, 'destroy'])->name('inventory.destroy');

    Route::post('inventory/{product}/discount', [ProductController::class, 'applyDiscount'])->name('inventory.discount');
    Route::post('inventory/{product}/donate', [ProductController::class, 'reserveDonation'])->name('inventory.donate');

    Route::get('agent-logs', function () {
        return Inertia::render('AgentLogs');
    })->name('agent-logs');

    Route::get('donation-partners', function () {
        return Inertia::render('DonationPartners');
    })->name('donation-partners');
});

// Backward-compatible alias for route('inventory')
Route::get('inventory', [ProductController::class, 'index'])->name('inventory')->middleware(['auth', 'verified']);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
