<?php

namespace App\Services;

use App\Models\DonationPartner;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class AiAgentService
{
    /**
     * Evaluate a single product using Cloud AI or fallback Mock Engine.
     *
     * @return array{
     *     action: 'discount'|'donate'|'none',
     *     discount_percentage: float|int,
     *     recommended_partner_id: int|null,
     *     reasoning: string,
     *     provider: string,
     * }
     */
    public function evaluateProduct(Product $product): array
    {
        $today = now()->startOfDay();
        $expDate = Carbon::parse($product->expiration_date)->startOfDay();
        $daysRemaining = (int) $today->diffInDays($expDate, false);

        // 1. If Mock Mode is explicitly forced, run the local deterministic engine
        if ($this->shouldUseMock()) {
            return $this->evaluateViaMock($product, $daysRemaining);
        }

        // 2. Attempt Google Gemini API first if configured
        if ($this->hasGeminiKey()) {
            try {
                $geminiResult = $this->evaluateViaGemini($product, $daysRemaining);
                if ($geminiResult !== null) {
                    return $geminiResult;
                }
            } catch (Throwable $e) {
                Log::warning('Gemini API evaluation failed, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Attempt Groq API as secondary cloud provider
        if ($this->hasGroqKey()) {
            try {
                $groqResult = $this->evaluateViaGroq($product, $daysRemaining);
                if ($groqResult !== null) {
                    return $groqResult;
                }
            } catch (Throwable $e) {
                Log::warning('Groq API evaluation failed, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Graceful Fallback: Deterministic Mock Engine
        return $this->evaluateViaMock($product, $daysRemaining, 'mock_fallback');
    }

    /**
     * Evaluate a collection of products.
     *
     * @param  Collection<int, Product>|array<int, Product>  $products
     * @return array<int, array{product: Product, decision: array<string, mixed>}>
     */
    public function evaluateBatch(Collection|array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $decision = $this->evaluateProduct($product);
            $results[] = [
                'product' => $product,
                'decision' => $decision,
            ];
        }

        return $results;
    }

    /**
     * Local Deterministic Mock Engine.
     *
     * @return array{
     *     action: 'discount'|'donate'|'none',
     *     discount_percentage: float|int,
     *     recommended_partner_id: int|null,
     *     reasoning: string,
     *     provider: string,
     * }
     */
    public function evaluateViaMock(Product $product, int $daysRemaining, string $providerName = 'mock'): array
    {
        $partnerId = DonationPartner::query()->where('is_active', true)->value('id');

        if ($daysRemaining <= 2) {
            return [
                'action' => 'donate',
                'discount_percentage' => 0,
                'recommended_partner_id' => $partnerId,
                'reasoning' => 'Urgent: Expires in less than 48h. Recommended for donation.',
                'provider' => $providerName,
            ];
        }

        if ($daysRemaining >= 3 && $daysRemaining <= 7) {
            return [
                'action' => 'discount',
                'discount_percentage' => 30,
                'recommended_partner_id' => null,
                'reasoning' => 'Optimal window for clearance sale discount.',
                'provider' => $providerName,
            ];
        }

        return [
            'action' => 'none',
            'discount_percentage' => 0,
            'recommended_partner_id' => null,
            'reasoning' => 'Stock condition safe. Shelf life exceeds clearance window.',
            'provider' => $providerName,
        ];
    }

    /**
     * Query Google Gemini API (Gemini 1.5 Flash).
     */
    protected function evaluateViaGemini(Product $product, int $daysRemaining): ?array
    {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.model', 'gemini-1.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = $this->buildPrompt($product, $daysRemaining);

        $response = Http::timeout(12)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.2,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Gemini API returned error response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $rawText = $response->json('candidates.0.content.parts.0.text');
        if (! $rawText) {
            return null;
        }

        return $this->parseAndValidateJson($rawText, $daysRemaining, 'gemini');
    }

    /**
     * Query Groq API (Llama 3 / Llama 3.1).
     */
    protected function evaluateViaGroq(Product $product, int $daysRemaining): ?array
    {
        $apiKey = (string) config('services.groq.api_key');
        $model = (string) config('services.groq.model', 'llama-3.1-8b-instant');
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $prompt = $this->buildPrompt($product, $daysRemaining);

        $response = Http::timeout(12)
            ->withToken($apiKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are the EcoLoop Autonomous Circular Economy Agent. Analyze food inventory items and recommend actions in strict structured JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            Log::warning('Groq API returned error response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $rawText = $response->json('choices.0.message.content');
        if (! $rawText) {
            return null;
        }

        return $this->parseAndValidateJson($rawText, $daysRemaining, 'groq');
    }

    /**
     * Build the structured prompt for LLMs.
     */
    protected function buildPrompt(Product $product, int $daysRemaining): string
    {
        $categoryName = $product->category?->name ?? 'General Food';
        $activePartners = DonationPartner::query()
            ->where('is_active', true)
            ->select('id', 'name')
            ->get()
            ->map(fn ($p) => "ID: {$p->id} ({$p->name})")
            ->implode(', ');

        return <<<PROMPT
You are EcoLoop Agent, an autonomous circular economy AI preventing supermarket food waste.
Analyze this product and output your decision strictly as JSON.

[PRODUCT DETAILS]
- Name: {$product->name}
- SKU: {$product->sku}
- Category: {$categoryName}
- Stock Quantity: {$product->quantity} {$product->unit}
- Original Retail Price: IDR {$product->original_price}
- Current Operational Status: {$product->status}
- Days Remaining Until Expiration: {$daysRemaining} day(s)

[AVAILABLE DONATION PARTNERS]
{$activePartners}

[DECISION RULES]
1. If Days Remaining <= 2: Perishable safety risk. Recommend "donate" to an available partner ID with 0 discount.
2. If 3 <= Days Remaining <= 7: Clearance markdown window. Recommend "discount" with appropriate percentage (between 20 and 50) and recommended_partner_id: null.
3. If Days Remaining > 7: Stock is safe. Recommend "none" with discount_percentage: 0 and recommended_partner_id: null.

[REQUIRED JSON SCHEMA]
Respond ONLY with a JSON object matching this schema without markdown fences:
{
  "action": "discount" | "donate" | "none",
  "discount_percentage": number,
  "recommended_partner_id": number | null,
  "reasoning": "string explanation"
}
PROMPT;
    }

    /**
     * Parse and sanitize raw JSON output from LLM.
     */
    protected function parseAndValidateJson(string $rawText, int $daysRemaining, string $provider): ?array
    {
        try {
            // Remove markdown code fences if present
            $cleanText = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawText)) ?? '');
            $data = json_decode($cleanText, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                return null;
            }

            $action = strtolower((string) ($data['action'] ?? 'none'));
            if (! in_array($action, ['discount', 'donate', 'none'], true)) {
                $action = 'none';
            }

            $discountPercentage = isset($data['discount_percentage']) ? (float) $data['discount_percentage'] : 0.0;
            if ($action === 'discount' && ($discountPercentage <= 0 || $discountPercentage >= 100)) {
                $discountPercentage = 30.0;
            } elseif ($action !== 'discount') {
                $discountPercentage = 0.0;
            }

            $partnerId = isset($data['recommended_partner_id']) && is_numeric($data['recommended_partner_id'])
                ? (int) $data['recommended_partner_id']
                : null;

            if ($action === 'donate' && ! $partnerId) {
                $partnerId = DonationPartner::query()->where('is_active', true)->value('id');
            }

            $reasoning = ! empty($data['reasoning']) && is_string($data['reasoning'])
                ? trim($data['reasoning'])
                : 'Automated AI circular economy evaluation.';

            return [
                'action' => $action,
                'discount_percentage' => $discountPercentage,
                'recommended_partner_id' => $partnerId,
                'reasoning' => $reasoning,
                'provider' => $provider,
            ];
        } catch (JsonException $e) {
            Log::warning("Failed to decode {$provider} JSON response", [
                'raw' => $rawText,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if Mock Mode is activated.
     */
    public function shouldUseMock(): bool
    {
        if (config('app.enable_mock_ai', true) === true) {
            return true;
        }

        return ! $this->hasGeminiKey() && ! $this->hasGroqKey();
    }

    /**
     * Check if Gemini API key is configured.
     */
    public function hasGeminiKey(): bool
    {
        return ! empty(config('services.gemini.api_key'));
    }

    /**
     * Check if Groq API key is configured.
     */
    public function hasGroqKey(): bool
    {
        return ! empty(config('services.groq.api_key'));
    }
}
