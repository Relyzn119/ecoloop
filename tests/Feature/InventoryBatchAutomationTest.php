<?php

use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function aiDecisionResponse(string $decision): array
{
    return [
        'choices' => [[
            'message' => ['content' => $decision],
        ]],
    ];
}

test('inventory intake evaluates each batch and only deducts the expiring donation lot', function () {
    config([
        'services.ninerouter.api_key' => 'test-key',
        'services.ninerouter.base_url' => 'http://127.0.0.1:20128/v1',
        'services.ninerouter.model' => 'test-model',
    ]);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(aiDecisionResponse('{"action":"donate","discount_percentage":0,"recommended_partner_id":1,"reasoning":"Urgent batch donation."}'))
            ->push(aiDecisionResponse('{"action":"discount","discount_percentage":30,"recommended_partner_id":null,"reasoning":"Clearance recommendation."}')),
    ]);

    $category = Category::factory()->create();
    $partner = DonationPartner::factory()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/inventory', [
        'category_id' => $category->id,
        'sku' => 'CHEDDAR-001',
        'name' => 'Cheddar Cheese',
        'description' => 'Supermarket intake lot test',
        'quantity' => 20,
        'unit' => 'pcs',
        'original_price' => 50000,
        'discount_price' => null,
        'expiration_date' => now()->addDays(1)->toDateString(),
        'status' => 'active',
        'batches' => [
            ['quantity' => 20, 'expiration_date' => now()->addDays(1)->toDateString()],
            ['quantity' => 30, 'expiration_date' => now()->addDays(4)->toDateString()],
        ],
    ]);

    $response->assertRedirect('/inventory');
    $product = Product::query()->where('sku', 'CHEDDAR-001')->firstOrFail();
    $batches = ProductBatch::query()->where('product_id', $product->id)->orderBy('expiration_date')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->quantity_available)->toBe(0)
        ->and($batches[0]->quantity_donated)->toBe(20)
        ->and($batches[0]->status)->toBe('donated')
        ->and($batches[1]->quantity_available)->toBe(30)
        ->and($product->fresh()->quantity)->toBe(30)
        ->and($product->fresh()->status)->toBe('active')
        ->and($product->fresh()->discount_price)->toBeNull()
        ->and($product->discountApprovals()->first()->recommended_percentage)->toBe(30)
        ->and($product->discountApprovals()->first()->status)->toBe('pending');

    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'partner_id' => $partner->id,
        'action_type' => 'donation_requested',
        'status' => 'success',
    ]);
    Http::assertSentCount(2);
});

test('a failed 9router call leaves received batches unchanged', function () {
    config([
        'services.ninerouter.api_key' => 'test-key',
        'services.ninerouter.base_url' => 'http://127.0.0.1:20128/v1',
    ]);
    Http::fake(['*/chat/completions' => Http::response(['error' => 'temporary failure'], 503)]);

    $category = Category::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/inventory', [
        'category_id' => $category->id,
        'sku' => 'FAILOVER-001',
        'name' => 'Fallback Test Food',
        'quantity' => 12,
        'unit' => 'pcs',
        'original_price' => 10000,
        'expiration_date' => now()->addDay()->toDateString(),
        'status' => 'active',
        'batches' => [
            ['quantity' => 12, 'expiration_date' => now()->addDay()->toDateString()],
        ],
    ])->assertRedirect('/inventory');

    $product = Product::query()->where('sku', 'FAILOVER-001')->firstOrFail();

    expect($product->fresh()->quantity)->toBe(12)
        ->and($product->batches()->first()->quantity_available)->toBe(12)
        ->and($product->batches()->first()->quantity_donated)->toBe(0)
        ->and($product->discountApprovals()->count())->toBe(0);
});
