<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentLogResource;
use App\Models\AgentLog;
use App\Models\DonationPartner;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AgentLogController extends Controller
{
    /**
     * Display a paginated audit trail of all AI agent decisions and manual overrides.
     */
    public function index(Request $request): Response
    {
        $query = AgentLog::query()
            ->with(['product.category', 'partner'])
            ->latest('id');

        // Filter by action type
        if ($request->filled('action_type') && $request->action_type !== 'all') {
            $query->where('action_type', $request->action_type);
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date') && $request->date !== 'all') {
            if ($request->date === 'today') {
                $query->whereDate('created_at', now()->toDateString());
            } elseif ($request->date === '7days') {
                $query->where('created_at', '>=', now()->subDays(7));
            } elseif ($request->date === '30days') {
                $query->where('created_at', '>=', now()->subDays(30));
            }
        }

        // Free-text search matching reasoning, product, SKU, partner, or payload
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(function ($q) use ($term) {
                $q->where('reasoning', 'like', "%{$term}%")
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('name', 'like', "%{$term}%")
                            ->orWhere('sku', 'like', "%{$term}%");
                    })
                    ->orWhereHas('partner', function ($ptq) use ($term) {
                        $ptq->where('name', 'like', "%{$term}%");
                    });
            });
        }

        $logs = $query->paginate(15)->withQueryString();

        // Calculate summary statistics for header metrics
        $totalLogs = AgentLog::count();
        $discountsCount = AgentLog::where('action_type', 'discount_applied')->count();
        $donationsCount = AgentLog::where('action_type', 'donation_requested')->count();
        $alertsCount = AgentLog::where('action_type', 'alert_sent')->count();
        $overridesCount = AgentLog::where('action_type', 'manual_override')->count();
        $successCount = AgentLog::where('status', 'success')->count();
        $successRate = $totalLogs > 0 ? round(($successCount / $totalLogs) * 100, 1) : 100.0;

        $activePartners = DonationPartner::query()
            ->where('is_active', true)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return Inertia::render('AgentLogs/Index', [
            'logs' => AgentLogResource::collection($logs),
            'filters' => [
                'search' => $request->search ?? '',
                'action_type' => $request->action_type ?? 'all',
                'status' => $request->status ?? 'all',
                'date' => $request->date ?? 'all',
            ],
            'stats' => [
                'total_logs' => $totalLogs,
                'discounts_count' => $discountsCount,
                'donations_count' => $donationsCount,
                'alerts_count' => $alertsCount,
                'overrides_count' => $overridesCount,
                'success_rate' => $successRate,
            ],
            'partners' => $activePartners,
        ]);
    }

    /**
     * Revert or modify an automated AI action with full audit recording.
     */
    public function override(Request $request, AgentLog $agentLog, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'override_action' => ['required', 'string', 'in:revert,reassign_partner'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'partner_id' => ['nullable', 'integer', 'exists:donation_partners,id'],
        ]);

        DB::transaction(function () use ($validated, $agentLog, $request) {
            $product = $agentLog->product;
            $overrideAction = $validated['override_action'];
            $reason = $validated['reason'];
            $newPartnerId = $validated['partner_id'] ?? null;

            if ($product) {
                if ($overrideAction === 'revert') {
                    if ($agentLog->action_type === 'discount_applied') {
                        $product->update([
                            'status' => 'active',
                            'discount_price' => null,
                        ]);
                    } elseif ($agentLog->action_type === 'donation_requested') {
                        $product->update([
                            'status' => 'active',
                        ]);
                    }
                } elseif ($overrideAction === 'reassign_partner' && $newPartnerId) {
                    $product->update([
                        'status' => 'reserved_donation',
                    ]);
                }
            }

            // Create manual override audit record
            $overrideLog = AgentLog::create([
                'product_id' => $agentLog->product_id,
                'partner_id' => $newPartnerId ?? $agentLog->partner_id,
                'action_type' => 'manual_override',
                'reasoning' => "Manual Override by {$request->user()?->name}: {$reason}",
                'payload' => [
                    'target_log_id' => $agentLog->id,
                    'original_action' => $agentLog->action_type,
                    'override_action' => $overrideAction,
                    'reason' => $reason,
                    'performed_by' => $request->user()?->name ?? 'Admin',
                    'product_sku' => $product?->sku,
                    'product_name' => $product?->name,
                    'previous_payload' => $agentLog->payload,
                    'override_details' => [
                        'partner_id' => $newPartnerId,
                    ],
                    'applied_at' => now()->toIso8601String(),
                ],
                'status' => 'success',
            ]);

            // Flag the original log record as overridden
            $currentPayload = $agentLog->payload ?? [];
            $currentPayload['is_overridden'] = true;
            $currentPayload['overridden_by'] = $request->user()?->name ?? 'Admin';
            $currentPayload['overridden_at'] = now()->toIso8601String();
            $currentPayload['override_log_id'] = $overrideLog->id;
            $currentPayload['override_reason'] = $reason;

            $agentLog->update(['payload' => $currentPayload]);
        });

        return redirect()->back()->with('success', "AI action #{$agentLog->id} was successfully overridden and audited.");
    }

    /**
     * Trigger a mock AI inventory evaluation run for live demonstration and testing.
     */
    public function simulate(InventoryService $inventoryService): RedirectResponse
    {
        $result = $inventoryService->simulateAiEvaluation();

        return redirect()->back()->with(
            'success',
            "AI Agent simulation completed: evaluated {$result['analyzed_count']} products and triggered {$result['actions_count']} autonomous action(s)."
        );
    }
}
