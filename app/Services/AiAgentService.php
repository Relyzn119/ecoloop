<?php

namespace App\Services;

use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\ProductBatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class AiAgentService
{
    /**
     * Evaluate visible food condition from a user-provided image.
     *
     * @return array{donation_eligible: bool, confidence: float, condition: string, reasoning: string, safety_note: string, provider: string}
     */
    public function evaluateDonationPhoto(Product $product, ProductBatch $batch, string $imageData, string $mimeType): array
    {
        $manualReview = [
            'donation_eligible' => false,
            'confidence' => 0.0,
            'condition' => 'unclear',
            'reasoning' => 'Analisis foto tidak tersedia. Periksa kondisi produk secara manual atau coba foto ulang.',
            'safety_note' => 'Foto tidak dapat memastikan keamanan mikrobiologis, bau, maupun kontaminasi makanan.',
            'provider' => 'manual_review',
        ];

        if (! $this->hasNineRouterKey()) {
            return $manualReview;
        }

        $model = (string) config('services.ninerouter.vision_model');
        $baseUrl = rtrim((string) config('services.ninerouter.base_url', 'http://127.0.0.1:20128/v1'), '/');
        $categoryName = $product->category?->name ?? 'General Food';
        $prompt = <<<PROMPT
Klasifikasikan kondisi visual produk ke dalam tepat satu kategori:
- good: masih layak dijual. donation_eligible HARUS false.
- acceptable: secara visual sudah tidak layak dijual karena cacat tampilan/kematangan/kemasan ringan, tetapi masih tampak berpotensi layak konsumsi dan donasi. donation_eligible boleh true hanya bila produk jelas cocok dan confidence tinggi.
- poor: tampak busuk, berjamur, rusak berat, atau ada tanda yang membuatnya tidak layak konsumsi. donation_eligible HARUS false.
- unclear: foto/produk/kondisi tidak jelas atau meragukan. donation_eligible HARUS false.
Jangan memilih acceptable hanya karena produk mendekati kedaluwarsa; tanggal tidak terlihat pada foto dan tidak membuktikan kondisi konsumsi.
Jika foto buram, objek tidak terlihat jelas, jenis makanan tidak dapat dikenali, atau kondisi meragukan, pilih donation_eligible=false dan condition="unclear".
Jangan pernah menyatakan makanan pasti aman. Foto tidak dapat menilai bau, suhu penyimpanan, kontaminasi, atau mikroorganisme. Jika ada keraguan, minta pemeriksaan manual.

Produk pada batch yang dipilih: {$product->name} (SKU {$product->sku}), kategori {$categoryName}, batch {$batch->batch_code}, stok tersedia {$batch->quantity_available} {$product->unit}, kedaluwarsa {$batch->expiration_date->toDateString()}.
Nilai apakah objek pada foto cocok dengan produk yang disebutkan. Jika tidak cocok, condition="unclear" dan donation_eligible=false. Jangan menganggap gambar saja membuktikan makanan aman.

Kembalikan HANYA JSON valid dengan key donation_eligible (boolean), confidence (angka 0 sampai 1), condition (salah satu good, acceptable, poor, unclear), reasoning (alasan singkat dalam Bahasa Indonesia), dan safety_note (batasan keselamatan singkat).
Contoh JSON valid: {"donation_eligible":false,"confidence":0.0,"condition":"unclear","reasoning":"Foto tidak cukup jelas.","safety_note":"Periksa produk secara manual."}
PROMPT;

        try {
            $response = Http::timeout(35)
                ->withToken((string) config('services.ninerouter.api_key'))
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a cautious visual food condition screening assistant. Never claim food is safe based on an image alone. Return only strict JSON.'],
                        ['role' => 'user', 'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mimeType.';base64,'.$imageData, 'detail' => 'low']],
                        ]],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                    'stream' => false,
                ]);

            if (! $response->successful()) {
                Log::warning('9Router vision evaluation returned an error.', ['status' => $response->status()]);

                return $manualReview;
            }

            $rawText = $response->json('choices.0.message.content');
            if (! is_string($rawText) || trim($rawText) === '') {
                return $manualReview;
            }

            $rawText = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawText)) ?? '');
            $decision = json_decode($rawText, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decision)) {
                return $manualReview;
            }

            $confidence = is_numeric($decision['confidence'] ?? null)
                ? max(0.0, min(1.0, (float) $decision['confidence']))
                : 0.0;
            $condition = in_array($decision['condition'] ?? null, ['good', 'acceptable', 'poor', 'unclear'], true)
                ? $decision['condition']
                : 'unclear';
            // Donation requires the explicit "acceptable" class: visually not saleable,
            // but without visible spoilage. "good" is saleable and must never enable donation.
            $eligible = ($decision['donation_eligible'] ?? false) === true
                && $confidence >= 0.75
                && $condition === 'acceptable';

            return [
                'donation_eligible' => $eligible,
                'confidence' => $confidence,
                'condition' => $condition,
                'reasoning' => is_string($decision['reasoning'] ?? null) ? trim($decision['reasoning']) : $manualReview['reasoning'],
                'safety_note' => is_string($decision['safety_note'] ?? null) ? trim($decision['safety_note']) : $manualReview['safety_note'],
                'provider' => '9router:'.$model,
            ];
        } catch (Throwable $exception) {
            Log::warning('9Router vision evaluation failed.', ['error' => $exception->getMessage()]);

            return $manualReview;
        }
    }

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
    public function evaluateProduct(Product $product, ?string $forceProvider = null): array
    {
        $today = now()->startOfDay();
        $expDate = Carbon::parse($product->expiration_date)->startOfDay();
        $daysRemaining = (int) $today->diffInDays($expDate, false);

        // Force specific provider if requested during live sandbox simulation
        if ($forceProvider === 'mock') {
            return $this->evaluateViaMock($product, $daysRemaining, 'mock');
        }

        if ($forceProvider === 'gemini' && $this->hasGeminiKey()) {
            try {
                $geminiResult = $this->evaluateViaGemini($product, $daysRemaining);
                if ($geminiResult !== null) {
                    return $geminiResult;
                }
            } catch (Throwable $e) {
                Log::warning('Gemini API evaluation failed during forced run, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($forceProvider === 'groq' && $this->hasGroqKey()) {
            try {
                $groqResult = $this->evaluateViaGroq($product, $daysRemaining);
                if ($groqResult !== null) {
                    return $groqResult;
                }
            } catch (Throwable $e) {
                Log::warning('Groq API evaluation failed during forced run, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($forceProvider === '9router' && $this->hasNineRouterKey()) {
            try {
                $routerResult = $this->evaluateViaNineRouter($product, $daysRemaining);
                if ($routerResult !== null) {
                    return $routerResult;
                }
            } catch (Throwable $e) {
                Log::warning('9Router API evaluation failed during forced run, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        // 4. Use the locally configured OpenAI-compatible 9Router gateway.
        if ($this->hasNineRouterKey()) {
            try {
                $routerResult = $this->evaluateViaNineRouter($product, $daysRemaining);
                if ($routerResult !== null) {
                    return $routerResult;
                }
            } catch (Throwable $e) {
                Log::warning('9Router API evaluation failed, falling back.', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 5. Graceful Fallback: Deterministic Mock Engine
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
     * Query the local OpenAI-compatible 9Router gateway.
     */
    protected function evaluateViaNineRouter(Product $product, int $daysRemaining): ?array
    {
        $apiKey = (string) config('services.ninerouter.api_key');
        $model = (string) config('services.ninerouter.model', 'openrouter/google/gemma-4-31b-it:free');
        $baseUrl = rtrim((string) config('services.ninerouter.base_url', 'http://127.0.0.1:20128/v1'), '/');
        $prompt = $this->buildPrompt($product, $daysRemaining);

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are the EcoLoop food-waste prevention evaluator. Return only a JSON object with action, discount_percentage, recommended_partner_id, and reasoning.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.2,
                'stream' => false,
            ]);

        if (! $response->successful()) {
            Log::warning('9Router API returned error response', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $rawText = $response->json('choices.0.message.content');
        if (! is_string($rawText) || trim($rawText) === '') {
            return null;
        }

        return $this->parseAndValidateJson($rawText, $daysRemaining, '9router');
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
            if ($action === 'discount' && ($discountPercentage <= 0 || $discountPercentage > 50)) {
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

            if ($action === 'donate' && $partnerId !== null && ! DonationPartner::query()
                ->whereKey($partnerId)
                ->where('is_active', true)
                ->exists()) {
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

        return ! $this->hasGeminiKey() && ! $this->hasGroqKey() && ! $this->hasNineRouterKey();
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

    public function hasNineRouterKey(): bool
    {
        return ! empty(config('services.ninerouter.api_key'));
    }
}
