<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ApplyDiscountRequest;
use App\Http\Requests\Api\GetExpiringProductsRequest;
use App\Http\Requests\Api\ReserveDonationRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryApiController extends Controller
{
    /**
     * Dependency injection with PHP 8 constructor promotion.
     */
    public function __construct(
        protected readonly InventoryService $inventoryService
    ) {}

    /**
     * Query active products expiring within the specified threshold.
     */
    public function expiringSoon(GetExpiringProductsRequest $request): JsonResponse
    {
        $days = $request->integer('days', 7);
        $products = $this->inventoryService->getExpiringProducts($days);

        return response()->json([
            'success' => true,
            'message' => 'Expiring products retrieved successfully.',
            'meta' => [
                'days_threshold' => $days,
                'total_count' => $products->count(),
            ],
            'data' => ProductResource::collection($products),
        ], Response::HTTP_OK);
    }

    /**
     * Retrieve one product for an external inventory automation.
     */
    public function showProduct(Product $product): JsonResponse
    {
        $product->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data' => new ProductResource($product),
        ], Response::HTTP_OK);
    }

    /**
     * Apply a markdown discount to an inventory item and create an agent log.
     */
    public function applyDiscount(ApplyDiscountRequest $request): JsonResponse
    {
        $percentage = min(50, (int) round((float) $request->input('discount_percentage')));
        $approvals = $this->inventoryService->requestDiscountApproval(
            productId: $request->integer('product_id'),
            discountPercentage: $percentage,
            reason: $request->string('reason')->toString()
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount recommendation sent for manager or cashier approval; no price was changed.',
            'data' => ['approval_ids' => collect($approvals)->pluck('id')],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Reserve an expiring product for charity/food bank donation dispatch.
     */
    public function reserveDonation(ReserveDonationRequest $request): JsonResponse
    {
        $product = $this->inventoryService->reserveForDonation(
            productId: $request->integer('product_id'),
            partnerId: $request->integer('partner_id'),
            reason: $request->string('reason')->toString()
        );

        return response()->json([
            'success' => true,
            'message' => 'Product successfully reserved for donation dispatch.',
            'data' => new ProductResource($product),
        ], Response::HTTP_OK);
    }

    /**
     * Check AI Integration mode and mock fallback availability status.
     */
    public function aiStatus(): JsonResponse
    {
        $isMockEnabled = (bool) config('app.enable_mock_ai', env('ENABLE_MOCK_AI', true));

        return response()->json([
            'success' => true,
            'message' => 'AI Agent Integration Status.',
            'data' => [
                'mock_mode_active' => $isMockEnabled,
                'providers' => [
                    'gemini' => ! empty(env('GEMINI_API_KEY')),
                    'groq' => ! empty(env('GROQ_API_KEY')),
                    '9router' => app(AiAgentService::class)->hasNineRouterKey(),
                    'n8n_webhook' => ! empty(env('N8N_WEBHOOK_URL')),
                ],
                'features' => [
                    'autonomous_markdown' => true,
                    'donation_dispatch' => true,
                    'simulation_runner' => true,
                ],
                'timestamp' => now()->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Evaluate one product for n8n without applying inventory changes.
     */
    public function evaluateProduct(Request $request, AiAgentService $aiAgentService): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
        ]);

        $product = Product::query()
            ->with('category:id,name,slug')
            ->findOrFail($validated['product_id']);

        $decision = $aiAgentService->evaluateProduct($product, '9router');

        return response()->json([
            'success' => true,
            'product_id' => $product->id,
            'name' => $product->name,
            'product_name' => $product->name,
            'expiration_date' => $product->expiration_date?->toDateString(),
            'quantity' => $product->quantity,
            'original_price' => (float) $product->original_price,
            ...$decision,
        ], Response::HTTP_OK);
    }

    /**
     * Simulate an autonomous AI evaluation cycle locally without needing external n8n online.
     */
    public function simulateAiRun(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 3);
        $result = $this->inventoryService->simulateAiEvaluation($days);

        return response()->json([
            'success' => true,
            'message' => 'Autonomous AI evaluation simulation completed.',
            'data' => $result,
        ], Response::HTTP_OK);
    }

    /**
     * Webhook receiver for n8n or external AI pipelines.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:apply_discount,reserve_donation,health_ping'],
            'product_id' => ['required_unless:action,health_ping', 'integer', 'exists:products,id'],
            'discount_percentage' => ['required_if:action,apply_discount', 'integer', 'min:1', 'max:50'],
            'partner_id' => ['required_if:action,reserve_donation', 'integer', 'exists:donation_partners,id'],
            'reason' => ['required_unless:action,health_ping', 'string', 'min:3'],
        ]);

        if ($validated['action'] === 'health_ping') {
            return response()->json([
                'success' => true,
                'message' => 'EcoLoop n8n webhook listener active.',
            ], Response::HTTP_OK);
        }

        if ($validated['action'] === 'apply_discount') {
            $approvals = $this->inventoryService->requestDiscountApproval(
                productId: (int) $validated['product_id'],
                discountPercentage: (int) $validated['discount_percentage'],
                reason: (string) $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Discount recommendation sent for approval; no price was changed.',
                'data' => ['approval_ids' => collect($approvals)->pluck('id')],
            ], Response::HTTP_ACCEPTED);
        }

        if ($validated['action'] === 'reserve_donation') {
            $product = $this->inventoryService->reserveForDonation(
                productId: (int) $validated['product_id'],
                partnerId: (int) $validated['partner_id'],
                reason: (string) $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Donation reserved via n8n webhook.',
                'data' => new ProductResource($product),
            ], Response::HTTP_OK);
        }

        return response()->json(['success' => false, 'message' => 'Unrecognized action.'], Response::HTTP_BAD_REQUEST);
    }
}
