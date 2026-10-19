<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Dependency injection with PHP 8 constructor promotion.
     */
    public function __construct(
        protected readonly InventoryService $inventoryService
    ) {}

    /**
     * Display the main circular economy dashboard with real-time metrics and agent activity.
     */
    public function index(Request $request): Response
    {
        $today = now()->toDateString();
        $sevenDays = now()->addDays(7)->toDateString();

        // 1. Total expiring soon (<= 7 days, active products)
        $totalExpiringSoon = Product::query()
            ->where('status', 'active')
            ->whereDate('expiration_date', '>=', $today)
            ->whereDate('expiration_date', '<=', $sevenDays)
            ->count();

        // 2. Food saved (kg or units from discounted, reserved_donation, or donated)
        $savedProducts = Product::query()
            ->whereIn('status', ['discounted', 'reserved_donation', 'donated'])
            ->get();

        $foodSavedKg = (float) $savedProducts->sum('quantity');

        // 3. Financial loss prevented (in IDR: benchmark exchange rate: 1 USD = Rp 16,000)
        $exchangeRate = 16000;
        $financialLossPreventedUsd = $savedProducts->reduce(function (float $carry, Product $p) {
            $effectiveValue = $p->status === 'discounted' && $p->discount_price !== null
                ? (float) $p->discount_price * $p->quantity
                : (float) $p->original_price * $p->quantity;

            return $carry + $effectiveValue;
        }, 0.0);

        $financialLossPreventedIdr = round($financialLossPreventedUsd * $exchangeRate);

        // 4. CO2 emission reduction (EPA benchmark: 2.5 kg CO2e per 1 kg food waste diverted from landfill)
        $co2EmissionReducedKg = round($foodSavedKg * 2.5, 1);

        // 5. Recent Agent Logs (Latest 5 records with product and partner relationships)
        $rawLogs = AgentLog::query()
            ->with(['product.category'])
            ->latest('id')
            ->limit(5)
            ->get();

        $partnerIds = $rawLogs
            ->map(fn (AgentLog $log) => $log->payload['partner_id'] ?? null)
            ->filter()
            ->unique();

        $partners = DonationPartner::query()
            ->whereIn('id', $partnerIds)
            ->get()
            ->keyBy('id');

        $recentAgentLogs = $rawLogs->map(function (AgentLog $log) use ($partners) {
            $partnerId = $log->payload['partner_id'] ?? null;
            $partner = $partnerId && isset($partners[$partnerId]) ? $partners[$partnerId] : null;
            $partnerName = $partner?->name ?? ($log->payload['partner_name'] ?? ($log->payload['partner'] ?? ($log->payload['recipient'] ?? null)));

            return [
                'id' => $log->id,
                'action_type' => $log->action_type,
                'reasoning' => $log->reasoning,
                'payload' => $log->payload,
                'status' => $log->status,
                'created_at' => $log->created_at?->diffForHumans() ?? 'Just now',
                'created_at_exact' => $log->created_at?->toIso8601String(),
                'product' => $log->product ? [
                    'id' => $log->product->id,
                    'name' => $log->product->name,
                    'sku' => $log->product->sku,
                    'category' => $log->product->category?->name ?? 'General',
                ] : null,
                'partner' => $partner ? [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'email' => $partner->email,
                    'phone' => $partner->phone,
                ] : null,
                'partner_name' => $partnerName,
            ];
        });

        // 6. Urgent Items (expiring in <= 3 days)
        $urgentProducts = Product::query()
            ->with(['category:id,name,slug'])
            ->whereIn('status', ['active', 'discounted'])
            ->whereDate('expiration_date', '>=', $today)
            ->whereDate('expiration_date', '<=', now()->addDays(3)->toDateString())
            ->orderBy('expiration_date', 'asc')
            ->limit(6)
            ->get()
            ->map(function (Product $p) {
                $days = (int) now()->startOfDay()->diffInDays($p->expiration_date->startOfDay(), false);

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category?->name ?? 'General',
                    'quantity' => $p->quantity,
                    'unit' => $p->unit,
                    'original_price' => (float) $p->original_price,
                    'discount_price' => $p->discount_price !== null ? (float) $p->discount_price : null,
                    'expiration_date' => $p->expiration_date?->toDateString(),
                    'days_left' => $days,
                    'status' => $p->status,
                ];
            });

        // 7. Active donation partners count
        $activePartnersCount = DonationPartner::active()->count();

        return Inertia::render('Dashboard', [
            'metrics' => [
                'total_expiring_soon' => $totalExpiringSoon,
                'food_saved_kg' => $foodSavedKg,
                'financial_loss_prevented' => $financialLossPreventedIdr,
                'financial_loss_prevented_formatted' => 'Rp '.number_format($financialLossPreventedIdr, 0, ',', '.'),
                'co2_emission_reduced_kg' => $co2EmissionReducedKg,
                'active_partners_count' => $activePartnersCount,
            ],
            'recent_agent_logs' => $recentAgentLogs,
            'urgent_products' => $urgentProducts,
            'mock_ai_enabled' => (bool) config('app.enable_mock_ai', env('ENABLE_MOCK_AI', true)),
        ]);
    }
}
