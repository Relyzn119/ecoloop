<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [DashboardController::class, 'index'])->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('inventory', function () {
    return Inertia::render('Inventory');
})->name('inventory');

Route::get('agent-logs', function () {
    return Inertia::render('AgentLogs');
})->name('agent-logs');

Route::get('donation-partners', function () {
    return Inertia::render('DonationPartners');
})->name('donation-partners');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
