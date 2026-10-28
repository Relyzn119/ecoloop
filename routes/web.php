<?php

use App\Http\Controllers\AgentLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationPartnerController;
use App\Http\Controllers\PartnerPortalController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SimulationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? to_route(auth()->user()->landingRoute())
        : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'role:donation_partner,umkm_partner'])->group(function () {
    Route::get('partner/pending', [PartnerPortalController::class, 'pending'])->name('partner.pending');
});

Route::middleware(['auth', 'role:umkm_partner', 'partner.active'])->prefix('umkm')->name('umkm.')->group(function () {
    Route::get('offers', [PartnerPortalController::class, 'offers'])->name('offers');
});

Route::middleware(['auth', 'role:donation_partner', 'partner.active'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('scan', [PartnerPortalController::class, 'scan'])->name('scan');
    Route::post('scan/analyze', [PartnerPortalController::class, 'analyze'])->name('scan.analyze');
});

Route::middleware(['auth', 'verified', 'role:manager,cashier'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Product Inventory Management CRUD & Manual Overrides
    Route::get('inventory', [ProductController::class, 'index'])->name('inventory.index');
    Route::get('inventory/create', [ProductController::class, 'create'])->name('inventory.create');
    Route::post('inventory', [ProductController::class, 'store'])->name('inventory.store');
    Route::post('inventory/auto-sync/toggle', [ProductController::class, 'toggleAutoSync'])->name('inventory.auto-sync.toggle');
    Route::post('inventory/auto-sync/run', [ProductController::class, 'syncNow'])->name('inventory.auto-sync.run');
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

    // AI Agent Logs & Manual Overrides Audit Trail
    Route::get('agent-logs', [AgentLogController::class, 'index'])->name('agent-logs.index');
    Route::post('agent-logs/{agent_log}/override', [AgentLogController::class, 'override'])->name('agent-logs.override');
    Route::post('agent-logs/simulate', [AgentLogController::class, 'simulate'])->name('agent-logs.simulate');

    // Read-only inventory and autonomous agent status monitor
    Route::get('simulation', [SimulationController::class, 'index'])->name('simulation.index');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('simulation/vision/analyze', [SimulationController::class, 'analyzeDonationPhoto'])->name('simulation.vision.analyze');
    Route::post('simulation/vision/donate', [SimulationController::class, 'donateReviewedBatch'])->name('simulation.vision.donate');
});

Route::middleware(['auth', 'verified', 'role:manager'])->group(function () {
    Route::post('donation-partners/{donation_partner}/toggle-status', [DonationPartnerController::class, 'toggleStatus'])->name('donation-partners.toggle-status');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
