<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ApplyDiscountRequest;
use App\Http\Requests\Api\GetExpiringProductsRequest;
use App\Http\Requests\Api\ReserveDonationRequest;
use App\Http\Resources\ProductResource;
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
     * Apply a markdown discount to an inventory item and create an agent log.
     */
    public function applyDiscount(ApplyDiscountRequest $request): JsonResponse
    {
        $product = $this->inventoryService->applyDiscount(
            productId: $request->integer('product_id'),
            discountPercentage: (float) $request->input('discount_percentage'),
            reason: $request->string('reason')->toString()
        );

        return response()->json([
            'success' => true,
            'message' => 'Dynamic markdown discount applied successfully.',
            'data' => new ProductResource($product),
        ], Response::HTTP_OK);
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
            'discount_percentage' => ['required_if:action,apply_discount', 'numeric', 'min:1', 'max:99'],
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
            $product = $this->inventoryService->applyDiscount(
                productId: (int) $validated['product_id'],
                discountPercentage: (float) $validated['discount_percentage'],
                reason: (string) $validated['reason']
            );

            return response()->json([
                'success' => true,
                'message' => 'Discount applied via n8n webhook.',
                'data' => new ProductResource($product),
            ], Response::HTTP_OK);
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
