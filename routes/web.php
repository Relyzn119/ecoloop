<?php

use App\Http\Controllers\Admin\PartnerManagementController as AdminPartnerManagementController;
use App\Http\Controllers\AgentLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventorySyncHistoryController;
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

Route::middleware(['auth', 'role:donation_partner,umkm_partner,business_owner'])->group(function () {
    Route::get('partner/pending', [PartnerPortalController::class, 'pending'])->name('partner.pending');
});

// Keep legacy business-owner inventory URLs working after this role moves to the supermarket dashboard.
Route::middleware(['auth', 'verified', 'role:business_owner', 'partner.active'])
    ->get('business-owner/inventory', fn () => to_route('inventory.index'))
    ->name('business-owner.inventory');

Route::middleware(['auth', 'verified', 'role:ecoloop_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('partners', [AdminPartnerManagementController::class, 'index'])->name('partners.index');
    Route::post('partners/{donationPartner}/approve', [AdminPartnerManagementController::class, 'approve'])->name('partners.approve');
    Route::post('partners/{donationPartner}/toggle-status', [AdminPartnerManagementController::class, 'toggleStatus'])->name('partners.toggle-status');
    Route::delete('partners/{donationPartner}', [AdminPartnerManagementController::class, 'destroy'])->name('partners.destroy');
});

Route::middleware(['auth', 'role:umkm_partner', 'partner.active'])->prefix('umkm')->name('umkm.')->group(function () {
    Route::get('offers', [PartnerPortalController::class, 'offers'])->name('offers');
    Route::get('stores', [PartnerPortalController::class, 'stores'])->name('stores');
    Route::get('discount-history', [PartnerPortalController::class, 'history'])->name('discount-history');
});

Route::middleware(['auth', 'role:donation_partner', 'partner.active'])->prefix('partner')->name('partner.')->group(function () {
    Route::get('donations/today', [PartnerPortalController::class, 'donationToday'])->name('donations.today');
    Route::get('stores', [PartnerPortalController::class, 'stores'])->name('stores');
    Route::get('donations/history', [PartnerPortalController::class, 'history'])->name('donations.history');
    Route::get('scan', [PartnerPortalController::class, 'scan'])->name('scan');
    Route::post('scan/analyze', [PartnerPortalController::class, 'analyze'])->name('scan.analyze');
    Route::post('scan/claim', [PartnerPortalController::class, 'claim'])->name('scan.claim');
});

Route::middleware(['auth', 'verified', 'role:manager,cashier,business_owner', 'partner.active'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Product Inventory Management CRUD & Manual Overrides
    Route::get('inventory', [ProductController::class, 'index'])->name('inventory.index');
    Route::get('inventory/sync-history', [InventorySyncHistoryController::class, 'index'])->name('inventory.sync-history');
    Route::get('inventory/create', [ProductController::class, 'create'])->name('inventory.create');
    Route::post('inventory', [ProductController::class, 'store'])->name('inventory.store');
    Route::post('inventory/{product}/batches', [ProductController::class, 'addBatch'])->name('inventory.add-batch');
    Route::post('inventory/auto-sync/toggle', [ProductController::class, 'toggleAutoSync'])->name('inventory.auto-sync.toggle');
    Route::post('inventory/auto-sync/run', [ProductController::class, 'syncNow'])->name('inventory.auto-sync.run');
    Route::get('inventory/{product}/edit', [ProductController::class, 'edit'])->name('inventory.edit');
    Route::put('inventory/{product}', [ProductController::class, 'update'])->name('inventory.update');
    Route::delete('inventory/{product}', [ProductController::class, 'destroy'])->name('inventory.destroy');

    Route::post('inventory/{product}/discount', [ProductController::class, 'applyDiscount'])->name('inventory.discount');
    Route::post('inventory/{product}/donate', [ProductController::class, 'reserveDonation'])->name('inventory.donate');

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

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
