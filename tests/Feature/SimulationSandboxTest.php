<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DiscountApproval;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('unauthenticated users cannot view the AI simulation page', function () {
    $this->get(route('simulation.index'))->assertRedirect(route('login'));
});

test('AI simulation page shows current supermarket batches and pending discounts', function () {
    $category = Category::factory()->create(['name' => 'Dairy & Chilled']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'CHEDDAR-001',
        'name' => 'Cheddar Cheese',
        'quantity' => 30,
        'expiration_date' => now()->addDays(3)->toDateString(),
    ]);
    $urgentBatch = $product->batches()->create([
        'batch_code' => 'CHEDDAR-001-001',
        'quantity_received' => 20,
        'quantity_available' => 0,
        'quantity_donated' => 20,
        'quantity_sold' => 0,
        'expiration_date' => now()->addDay()->toDateString(),
        'original_price' => 50000,
        'status' => 'donated',
        'source' => 'manual',
    ]);
    $discountBatch = $product->batches()->create([
        'batch_code' => 'CHEDDAR-001-002',
        'quantity_received' => 30,
        'quantity_available' => 30,
        'quantity_donated' => 0,
        'quantity_sold' => 0,
        'expiration_date' => now()->addDays(4)->toDateString(),
        'original_price' => 50000,
        'status' => 'active',
        'source' => 'manual',
    ]);
    DiscountApproval::query()->create([
        'product_id' => $product->id,
        'product_batch_id' => $discountBatch->id,
        'recommended_percentage' => 30,
        'recommended_price' => 35000,
        'status' => 'pending',
        'reasoning' => 'Approaching expiration window.',
    ]);
    AgentLog::factory()->create([
        'product_id' => $product->id,
        'reasoning' => 'Donated the urgent lot.',
    ]);

    $response = $this->actingAs(User::factory()->create())->get(route('simulation.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Simulation/Index')
            ->has('batches', 2)
            ->where('batches.0.batch_code', $urgentBatch->batch_code)
            ->where('batches.0.quantity_donated', 20)
            ->where('batches.1.quantity_available', 30)
            ->has('pendingApprovals', 1)
            ->where('pendingApprovals.0.recommended_percentage', 30)
            ->has('recentLogs', 1)
            ->has('lastUpdatedAt')
        );
});

test('simulation page no longer exposes actions that mutate inventory', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/simulation/trigger')->assertNotFound();
    $this->actingAs($user)->post('/simulation/reset/1')->assertNotFound();
});

test('a refresh request can reload the simulation page without writing inventory', function () {
    $product = Product::factory()->create([
        'quantity' => 10,
        'expiration_date' => now()->addDays(10)->toDateString(),
    ]);
    $product->batches()->create([
        'batch_code' => 'REFRESH-001',
        'quantity_received' => 10,
        'quantity_available' => 10,
        'expiration_date' => $product->expiration_date,
        'original_price' => $product->original_price,
        'status' => 'active',
        'source' => 'manual',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('simulation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('batches.0.quantity_available', 10));

    expect($product->fresh()->quantity)->toBe(10)
        ->and($product->fresh()->status)->toBe('active')
        ->and(AgentLog::query()->count())->toBe(0);
});
