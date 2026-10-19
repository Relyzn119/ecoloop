<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('categories can be created and hold products', function () {
    $category = Category::factory()->create([
        'name' => 'Bakery & Grains',
        'slug' => 'bakery-grains',
    ]);

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Sourdough Loaf',
    ]);

    expect($category->products)->toHaveCount(1)
        ->and($category->products->first()->name)->toBe('Sourdough Loaf');
});

test('products have proper casts, scopes, and relationships', function () {
    $category = Category::factory()->create();

    $product = Product::create([
        'category_id' => $category->id,
        'sku' => 'ECL-TST-0001',
        'name' => 'Organic Strawberries',
        'description' => 'Fresh ripe berries.',
        'quantity' => 15,
        'unit' => 'packs',
        'original_price' => '4.50',
        'discount_price' => '2.25',
        'expiration_date' => now()->addDays(2)->toDateString(),
        'status' => 'discounted',
    ]);

    expect($product->quantity)->toBeInt()->toBe(15)
        ->and($product->original_price)->toBe('4.50')
        ->and($product->discount_price)->toBe('2.25')
        ->and($product->expiration_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($product->category->id)->toBe($category->id);

    // Test scopes
    expect(Product::active()->count())->toBe(1)
        ->and(Product::expiringSoon(3)->count())->toBe(1)
        ->and(Product::expiringSoon(1)->count())->toBe(0);
});

test('donation partners can be filtered by active status', function () {
    DonationPartner::factory()->create(['name' => 'Active Food Bank', 'is_active' => true]);
    DonationPartner::factory()->inactive()->create(['name' => 'Closed Shelter']);

    expect(DonationPartner::active()->count())->toBe(1)
        ->and(DonationPartner::active()->first()->name)->toBe('Active Food Bank')
        ->and(DonationPartner::active()->first()->is_active)->toBeTrue();
});

test('agent logs store and cast json payloads correctly', function () {
    $product = Product::factory()->create();

    $log = AgentLog::create([
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
        'reasoning' => 'Shelf life under 24h threshold. Auto markdown applied.',
        'payload' => [
            'markdown_percent' => 50,
            'confidence' => 0.98,
        ],
        'status' => 'success',
    ]);

    expect($log->payload)->toBeArray()
        ->and($log->payload['markdown_percent'])->toBe(50)
        ->and($log->product->id)->toBe($product->id);
});

test('database seeder populates realistic tiers of test data', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::count())->toBeGreaterThanOrEqual(4)
        ->and(Product::count())->toBeGreaterThanOrEqual(15)
        ->and(DonationPartner::active()->count())->toBeGreaterThanOrEqual(3)
        ->and(AgentLog::count())->toBeGreaterThanOrEqual(1);

    // Check expiration tiers exist in seeded data
    $expiringInTwoDays = Product::where('expiration_date', '<=', now()->addDays(2)->toDateString())
        ->where('expiration_date', '>=', now()->toDateString())
        ->count();

    $expiringInFiveDays = Product::where('expiration_date', '>', now()->addDays(2)->toDateString())
        ->where('expiration_date', '<=', now()->addDays(5)->toDateString())
        ->count();

    $expiringLater = Product::where('expiration_date', '>', now()->addDays(5)->toDateString())->count();

    expect($expiringInTwoDays)->toBeGreaterThanOrEqual(3)
        ->and($expiringInFiveDays)->toBeGreaterThanOrEqual(3)
        ->and($expiringLater)->toBeGreaterThanOrEqual(3);
});
