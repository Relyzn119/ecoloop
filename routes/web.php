<?php

use App\Http\Controllers\AgentLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationPartnerController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

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

    // Donation Partners Management CRUD & Status Toggle
    Route::get('donation-partners', [DonationPartnerController::class, 'index'])->name('donation-partners.index');
    Route::post('donation-partners', [DonationPartnerController::class, 'store'])->name('donation-partners.store');
    Route::put('donation-partners/{donation_partner}', [DonationPartnerController::class, 'update'])->name('donation-partners.update');
    Route::delete('donation-partners/{donation_partner}', [DonationPartnerController::class, 'destroy'])->name('donation-partners.destroy');
    Route::post('donation-partners/{donation_partner}/toggle-status', [DonationPartnerController::class, 'toggleStatus'])->name('donation-partners.toggle-status');

    // AI Agent Logs & Manual Overrides Audit Trail
    Route::get('agent-logs', [AgentLogController::class, 'index'])->name('agent-logs.index');
    Route::post('agent-logs/{agent_log}/override', [AgentLogController::class, 'override'])->name('agent-logs.override');
    Route::post('agent-logs/simulate', [AgentLogController::class, 'simulate'])->name('agent-logs.simulate');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
