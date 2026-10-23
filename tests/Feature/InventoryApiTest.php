<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('can retrieve expiring soon products via api', function () {
    $category = Category::factory()->create();

    // Product expiring in 2 days (should be included)
    $p1 = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Ripe Berries',
        'expiration_date' => now()->addDays(2)->toDateString(),
        'status' => 'active',
    ]);

    // Product expiring in 10 days (should be excluded when query is ?days=5)
    $p2 = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Canned Beans',
        'expiration_date' => now()->addDays(10)->toDateString(),
        'status' => 'active',
    ]);

    // Query default (7 days)
    $response = $this->getJson('/api/v1/inventory/expiring-soon');
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.days_threshold', 7)
        ->assertJsonPath('meta.total_count', 1)
        ->assertJsonFragment(['name' => 'Ripe Berries']);

    // Query with ?days=15 (includes both)
    $response15 = $this->getJson('/api/v1/inventory/expiring-soon?days=15');
    $response15->assertStatus(200)
        ->assertJsonPath('meta.total_count', 2);
});

test('can apply markdown discount via api', function () {
    $product = Product::factory()->create([
        'original_price' => 10.00,
        'discount_price' => null,
        'status' => 'active',
    ]);

    $payload = [
        'product_id' => $product->id,
        'discount_percentage' => 40,
        'reason' => 'AI dynamic markdown: Expiring within 48h.',
    ];

    $response = $this->postJson('/api/v1/inventory/apply-discount', $payload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'discounted')
        ->assertJsonPath('data.discount_price', fn ($val) => (float) $val === 6.00);

    // Verify database updates
    expect($product->fresh()->status)->toBe('discounted')
        ->and((float) $product->fresh()->discount_price)->toBe(6.00);

    // Verify agent log entry
    $log = AgentLog::where('product_id', $product->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->action_type)->toBe('discount_applied')
        ->and($log->reasoning)->toBe('AI dynamic markdown: Expiring within 48h.')
        ->and($log->payload['discount_percentage'])->toBe(40);
});

test('apply discount endpoint validates input strictly', function () {
    $response = $this->postJson('/api/v1/inventory/apply-discount', [
        'product_id' => 99999, // non-existent
        'discount_percentage' => 150, // invalid percentage
        'reason' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id', 'discount_percentage', 'reason']);
});

test('can reserve product for donation via api', function () {
    $product = Product::factory()->create(['status' => 'active']);
    $partner = DonationPartner::factory()->create(['name' => 'City Food Bank']);

    $payload = [
        'product_id' => $product->id,
        'partner_id' => $partner->id,
        'reason' => 'Item shelf-life reached 24h threshold. Donated to City Food Bank.',
    ];

    $response = $this->postJson('/api/v1/inventory/reserve-donation', $payload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'reserved_donation');

    expect($product->fresh()->status)->toBe('reserved_donation');

    $log = AgentLog::where('product_id', $product->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->action_type)->toBe('donation_requested')
        ->and($log->payload['partner_name'])->toBe('City Food Bank');
});

test('reserve donation endpoint validates input strictly', function () {
    $response = $this->postJson('/api/v1/inventory/reserve-donation', [
        'product_id' => 99999,
        'partner_id' => 88888,
        'reason' => 'ab', // too short
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['product_id', 'partner_id', 'reason']);
});

test('ai status endpoint returns mock configuration and features', function () {
    $response = $this->getJson('/api/v1/ai/status');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'mock_mode_active',
                'providers',
                'features',
                'timestamp',
            ],
        ]);
});

test('ai simulate run triggers autonomous decisions locally without n8n', function () {
    config([
        'services.ninerouter.api_key' => 'test-key',
        'services.ninerouter.base_url' => 'http://127.0.0.1:20128/v1',
    ]);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '{"action":"donate","discount_percentage":0,"recommended_partner_id":1,"reasoning":"Urgent."}']]]])
            ->push(['choices' => [['message' => ['content' => '{"action":"discount","discount_percentage":30,"recommended_partner_id":null,"reasoning":"Clearance."}']]]]),
    ]);
    $category = Category::factory()->create();
    $partner = DonationPartner::factory()->create(['is_active' => true]);

    // Product expiring in 1 day (should be reserved for donation)
    $p1 = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Salmon Fillet',
        'expiration_date' => now()->addDays(1)->toDateString(),
        'status' => 'active',
    ]);

    // Product expiring in 3 days (should be discounted)
    $p2 = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Yogurt Tub',
        'expiration_date' => now()->addDays(3)->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/v1/ai/simulate-run', ['days' => 3]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.actions_count', 2);

    expect($p1->fresh()->status)->toBe('donated')
        ->and($p1->fresh()->quantity)->toBe(0)
        ->and($p2->fresh()->status)->toBe('active')
        ->and($p2->fresh()->discount_price)->toBeNull()
        ->and($p2->discountApprovals()->where('status', 'pending')->exists())->toBeTrue()
        ->and($p1->batches()->first()->quantity_donated)->toBe($p1->batches()->first()->quantity_received);
});

test('n8n webhook endpoint accepts external automation commands', function () {
    $product = Product::factory()->create(['original_price' => 20.00]);

    // Ping check
    $this->postJson('/api/v1/ai/webhook', ['action' => 'health_ping'])
        ->assertStatus(200)
        ->assertJsonPath('message', 'EcoLoop n8n webhook listener active.');

    // External discount command
    $this->postJson('/api/v1/ai/webhook', [
        'action' => 'apply_discount',
        'product_id' => $product->id,
        'discount_percentage' => 25,
        'reason' => 'Dispatched from n8n AI workflow.',
    ])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.discount_price', fn ($val) => (float) $val === 15.00);

    expect($product->fresh()->status)->toBe('discounted');
});

test('inventory service throws exception on invalid discount percentage', function () {
    $service = app(InventoryService::class);
    $product = Product::factory()->create();

    expect(fn () => $service->applyDiscount($product->id, 0, 'Zero discount'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->applyDiscount($product->id, 100, 'Full discount'))
        ->toThrow(InvalidArgumentException::class);
});
