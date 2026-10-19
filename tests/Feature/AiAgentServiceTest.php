<?php

use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Services\AiAgentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

test('ai agent service evaluates urgent products for donation in mock mode', function () {
    $partner = DonationPartner::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['name' => 'Bakery']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Organic Croissant',
        'expiration_date' => now()->addDays(1)->toDateString(),
        'status' => 'active',
    ]);

    Config::set('app.enable_mock_ai', true);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('donate')
        ->and($decision['discount_percentage'])->toBe(0)
        ->and($decision['recommended_partner_id'])->toBe($partner->id)
        ->and($decision['reasoning'])->toContain('Expires in less than 48h')
        ->and($decision['provider'])->toBe('mock');
});

test('ai agent service evaluates medium expiry products for discount in mock mode', function () {
    $category = Category::factory()->create(['name' => 'Dairy']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Fresh Milk 1L',
        'expiration_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ]);

    Config::set('app.enable_mock_ai', true);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('discount')
        ->and($decision['discount_percentage'])->toBe(30)
        ->and($decision['recommended_partner_id'])->toBeNull()
        ->and($decision['reasoning'])->toContain('clearance sale discount')
        ->and($decision['provider'])->toBe('mock');
});

test('ai agent service evaluates distant expiry products as none in mock mode', function () {
    $category = Category::factory()->create(['name' => 'Pantry']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Rice 5kg',
        'expiration_date' => now()->addDays(15)->toDateString(),
        'status' => 'active',
    ]);

    Config::set('app.enable_mock_ai', true);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('none')
        ->and($decision['discount_percentage'])->toBe(0)
        ->and($decision['recommended_partner_id'])->toBeNull()
        ->and($decision['provider'])->toBe('mock');
});

test('ai agent service interacts with gemini cloud api when configured', function () {
    Config::set('app.enable_mock_ai', false);
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.gemini.model', 'gemini-1.5-flash');

    $category = Category::factory()->create(['name' => 'Meat & Seafood']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Salmon Fillet 200g',
        'expiration_date' => now()->addDays(3)->toDateString(),
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'action' => 'discount',
                                    'discount_percentage' => 45.0,
                                    'recommended_partner_id' => null,
                                    'reasoning' => 'Perishable premium salmon benefits from 45% clearance velocity.',
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('discount')
        ->and($decision['discount_percentage'])->toBe(45.0)
        ->and($decision['reasoning'])->toContain('salmon')
        ->and($decision['provider'])->toBe('gemini');
});

test('ai agent service falls back to groq api when gemini fails', function () {
    Config::set('app.enable_mock_ai', false);
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.groq.api_key', 'test-groq-key');

    $category = Category::factory()->create(['name' => 'Bakery']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Bagel Pack',
        'expiration_date' => now()->addDays(4)->toDateString(),
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        'https://api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'action' => 'discount',
                            'discount_percentage' => 35.0,
                            'recommended_partner_id' => null,
                            'reasoning' => 'Llama 3 evaluated optimal discount of 35% for bakery clearance.',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('discount')
        ->and($decision['discount_percentage'])->toBe(35.0)
        ->and($decision['provider'])->toBe('groq');
});

test('ai agent service falls back to mock engine when all cloud apis fail', function () {
    Config::set('app.enable_mock_ai', false);
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.groq.api_key', 'test-groq-key');

    $category = Category::factory()->create(['name' => 'Bakery']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Donut Box',
        'expiration_date' => now()->addDays(1)->toDateString(),
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Server error'], 500),
        'https://api.groq.com/*' => Http::response(['error' => 'Server error'], 500),
    ]);

    $service = new AiAgentService;
    $decision = $service->evaluateProduct($product);

    expect($decision['action'])->toBe('donate')
        ->and($decision['provider'])->toBe('mock_fallback');
});

test('scan inventory command evaluates products and logs to agent_logs', function () {
    Config::set('app.enable_mock_ai', true);

    $category = Category::factory()->create(['name' => 'Produce']);
    $partner = DonationPartner::factory()->create(['is_active' => true]);

    // Product 1: Urgent (1 day) -> Donate
    $urgentProduct = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'EXP-URG-01',
        'name' => 'Ripe Strawberries',
        'quantity' => 10,
        'expiration_date' => now()->addDays(1)->toDateString(),
        'status' => 'active',
    ]);

    // Product 2: Medium (4 days) -> Discount
    $discountProduct = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'EXP-MED-01',
        'name' => 'Organic Apples',
        'quantity' => 20,
        'original_price' => 30000,
        'expiration_date' => now()->addDays(4)->toDateString(),
        'status' => 'active',
    ]);

    $this->artisan('ecoloop:scan-inventory', ['--days' => 7])
        ->assertSuccessful();

    // Urgent item should be reserved for donation
    $urgentProduct->refresh();
    expect($urgentProduct->status)->toBe('reserved_donation');
    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $urgentProduct->id,
        'action_type' => 'donation_requested',
    ]);

    // Medium item should be discounted
    $discountProduct->refresh();
    expect($discountProduct->status)->toBe('discounted');
    expect((float) $discountProduct->discount_price)->toBe(21000.0); // 30% off 30,000
    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $discountProduct->id,
        'action_type' => 'discount_applied',
    ]);
});

test('scan inventory command in dry run mode logs without mutating product statuses', function () {
    Config::set('app.enable_mock_ai', true);

    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'DRY-RUN-01',
        'expiration_date' => now()->addDays(2)->toDateString(),
        'status' => 'active',
    ]);

    $this->artisan('ecoloop:scan-inventory', ['--days' => 5, '--dry-run' => true])
        ->assertSuccessful();

    // Status should remain unchanged in dry run
    $product->refresh();
    expect($product->status)->toBe('active');

    // But audit entry should still be logged
    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'alert_sent',
    ]);
});
