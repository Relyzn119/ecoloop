<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentLogResource;
use App\Models\AgentLog;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SimulationController extends Controller
{
    /**
     * Render the dedicated AI Simulation Sandbox for live demonstrations.
     */
    public function index(): Response
    {
        $products = Product::query()
            ->with('category:id,name')
            ->orderByRaw("CASE WHEN status = 'active' THEN 1 WHEN status = 'discounted' THEN 2 ELSE 3 END")
            ->orderBy('expiration_date', 'asc')
            ->get()
            ->map(function (Product $product) {
                $days = (int) now()->startOfDay()->diffInDays($product->expiration_date, false);

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category?->name ?? 'General',
                    'original_price' => (float) $product->original_price,
                    'discount_price' => $product->discount_price ? (float) $product->discount_price : null,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'status' => $product->status,
                    'expiration_date' => $product->expiration_date->toDateString(),
                    'days_remaining' => $days,
                ];
            });

        $partners = DonationPartner::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'contact_person', 'email', 'phone', 'impact_score'])
            ->orderBy('name')
            ->get();

        $recentLogs = AgentLog::query()
            ->with(['product:id,name,sku', 'partner:id,name'])
            ->latest('id')
            ->limit(5)
            ->get();

        $n8nUrl = config('services.n8n.webhook_url');

        $configInfo = [
            'has_gemini' => ! empty(config('services.gemini.api_key')),
            'gemini_model' => config('services.gemini.model', 'gemini-1.5-flash'),
            'has_groq' => ! empty(config('services.groq.api_key')),
            'groq_model' => config('services.groq.model', 'llama-3.1-8b-instant'),
            'has_n8n' => ! empty($n8nUrl),
            'n8n_url' => $n8nUrl ? preg_replace('/(?<=:\/\/).+?(?=@)/', '***', (string) $n8nUrl) : null,
            'mock_mode_default' => (bool) config('app.enable_mock_ai', true),
        ];

        return Inertia::render('Simulation/Index', [
            'products' => $products,
            'partners' => $partners,
            'recentLogs' => AgentLogResource::collection($recentLogs),
            'config' => $configInfo,
        ]);
    }

    /**
     * Trigger real-time AI evaluation, simulated date offset, and optional n8n webhook dispatch.
     */
    public function triggerProductEvaluation(
        Request $request,
        AiAgentService $aiAgentService,
        InventoryService $inventoryService
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'date_offset' => ['nullable', 'integer', 'min:-30', 'max:365'],
            'custom_expiration_date' => ['nullable', 'date'],
            'provider' => ['nullable', 'string', 'in:auto,gemini,groq,mock'],
            'force_reset_status' => ['nullable', 'boolean'],
            'dispatch_n8n' => ['nullable', 'boolean'],
        ]);

        /** @var Product $product */
        $product = Product::with('category')->findOrFail($validated['product_id']);

        // 1. Apply simulated date offset if requested (e.g. 1 day or 4 days from now)
        if (array_key_exists('date_offset', $validated) && $validated['date_offset'] !== null) {
            $offset = (int) $validated['date_offset'];
            $product->expiration_date = now()->addDays($offset)->toDateString();

            // Reset status to active so evaluation can transition freely
            if ($product->status !== 'active' || ! empty($validated['force_reset_status'])) {
                $product->status = 'active';
                $product->discount_price = null;
            }
            $product->save();
        } elseif (! empty($validated['custom_expiration_date'])) {
            $product->expiration_date = Carbon::parse($validated['custom_expiration_date'])->toDateString();
            if (! empty($validated['force_reset_status'])) {
                $product->status = 'active';
                $product->discount_price = null;
            }
            $product->save();
        }

        // 2. Execute AI Agent Evaluation
        $provider = (! empty($validated['provider']) && $validated['provider'] !== 'auto')
            ? $validated['provider']
            : null;

        $decision = $aiAgentService->evaluateProduct($product, $provider);
        $action = $decision['action'];
        $reasoning = $decision['reasoning'];
        $detectedProvider = $decision['provider'];

        // 3. Dynamically apply decision to database state
        $executedAction = [];

        if ($action === 'discount') {
            $pct = (float) ($decision['discount_percentage'] ?: 30.0);
            $product = $inventoryService->applyDiscount($product->id, $pct, "[Live Sandbox / {$detectedProvider}] {$reasoning}");

            $executedAction = [
                'type' => 'discount',
                'label' => 'Dynamic Price Markdown',
                'status' => 'discounted',
                'discount_percentage' => $pct,
                'original_price' => (float) $product->original_price,
                'new_price' => (float) $product->discount_price,
                'summary' => sprintf(
                    'Applied dynamic %.0f%% discount. Retail price reduced from IDR %s to IDR %s.',
                    $pct,
                    number_format((float) $product->original_price),
                    number_format((float) $product->discount_price)
                ),
            ];
        } elseif ($action === 'donate') {
            $partnerId = $decision['recommended_partner_id']
                ?? DonationPartner::query()->where('is_active', true)->value('id');

            if ($partnerId) {
                $product = $inventoryService->reserveForDonation($product->id, (int) $partnerId, "[Live Sandbox / {$detectedProvider}] {$reasoning}");
                $partner = DonationPartner::find($partnerId);

                $executedAction = [
                    'type' => 'donate',
                    'label' => 'Immediate Donation Dispatch',
                    'status' => 'reserved_donation',
                    'partner_id' => $partnerId,
                    'partner_name' => $partner?->name,
                    'summary' => sprintf(
                        'Reserved %d %s for urgent dispatch to %s (Zero Landfill Waste Protocol).',
                        $product->quantity,
                        $product->unit,
                        $partner?->name ?? 'Charity Partner'
                    ),
                ];
            } else {
                $executedAction = [
                    'type' => 'alert',
                    'label' => 'Donation Pending',
                    'status' => 'pending',
                    'summary' => 'AI recommended donation, but no active donation partner is currently registered.',
                ];
            }
        } else {
            // Action is none: Record audit alert
            $daysRemaining = (int) now()->startOfDay()->diffInDays($product->expiration_date, false);
            AgentLog::create([
                'product_id' => $product->id,
                'action_type' => 'alert_sent',
                'reasoning' => "[Live Sandbox / {$detectedProvider}] {$reasoning}",
                'payload' => [
                    'product_sku' => $product->sku,
                    'days_remaining' => $daysRemaining,
                    'decision' => $decision,
                    'simulation' => true,
                ],
                'status' => 'success',
            ]);

            $executedAction = [
                'type' => 'none',
                'label' => 'Standard Retention',
                'status' => 'active',
                'summary' => 'Shelf-life is optimal. Product retained at full retail price without markdown.',
            ];
        }

        // 4. Handle External n8n Webhook Triggering
        $n8nUrl = config('services.n8n.webhook_url');
        $webhookResult = [
            'configured' => ! empty($n8nUrl),
            'url' => $n8nUrl ? preg_replace('/(?<=:\/\/).+?(?=@)/', '***', (string) $n8nUrl) : null,
            'status' => 'simulated',
            'http_code' => null,
            'payload' => [
                'event' => 'ecoloop.ai_evaluation',
                'timestamp' => now()->toIso8601String(),
                'product' => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'original_price' => (float) $product->original_price,
                    'discount_price' => $product->discount_price ? (float) $product->discount_price : null,
                    'status' => $product->status,
                    'expiration_date' => $product->expiration_date->toDateString(),
                    'days_remaining' => (int) now()->startOfDay()->diffInDays($product->expiration_date, false),
                ],
                'ai_decision' => $decision,
                'executed_action' => $executedAction,
            ],
        ];

        if (! empty($n8nUrl) && filter_var($n8nUrl, FILTER_VALIDATE_URL)) {
            try {
                $response = Http::timeout(6)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($n8nUrl, $webhookResult['payload']);

                $webhookResult['status'] = $response->successful() ? 'dispatched_success' : 'dispatched_error';
                $webhookResult['http_code'] = $response->status();
                $webhookResult['response_body'] = $response->json() ?? $response->body();
            } catch (Throwable $e) {
                $webhookResult['status'] = 'connection_error';
                $webhookResult['error'] = $e->getMessage();
            }
        }

        // 5. Fetch the freshly created audit log
        $latestLog = AgentLog::query()
            ->with(['product:id,name,sku', 'partner:id,name'])
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $freshProduct = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category?->name ?? 'General',
            'original_price' => (float) $product->original_price,
            'discount_price' => $product->discount_price ? (float) $product->discount_price : null,
            'quantity' => $product->quantity,
            'unit' => $product->unit,
            'status' => $product->status,
            'expiration_date' => $product->expiration_date->toDateString(),
            'days_remaining' => (int) now()->startOfDay()->diffInDays($product->expiration_date, false),
        ];

        $responsePayload = [
            'success' => true,
            'message' => "AI Evaluation for {$product->name} completed.",
            'product' => $freshProduct,
            'decision' => $decision,
            'executed_action' => $executedAction,
            'agent_log' => $latestLog ? (new AgentLogResource($latestLog))->resolve() : null,
            'n8n' => $webhookResult,
        ];

        if ($request->wantsJson()) {
            return response()->json($responsePayload);
        }

        return redirect()->back()->with('simulation_result', $responsePayload);
    }

    /**
     * Reset a product's state back to pristine active condition for repeat demo simulations.
     */
    public function resetProduct(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $product->update([
            'status' => 'active',
            'discount_price' => null,
            'expiration_date' => now()->addDays(14)->toDateString(),
        ]);

        $productData = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category?->name ?? 'General',
            'original_price' => (float) $product->original_price,
            'discount_price' => null,
            'quantity' => $product->quantity,
            'unit' => $product->unit,
            'status' => 'active',
            'expiration_date' => $product->expiration_date->toDateString(),
            'days_remaining' => 14,
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Product {$product->sku} reset to default active state with 14 days expiration.",
                'product' => $productData,
            ]);
        }

        return redirect()->back()->with('success', "Product {$product->sku} reset to default active state.");
    }
}
