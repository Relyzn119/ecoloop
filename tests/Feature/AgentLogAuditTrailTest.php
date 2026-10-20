<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('unauthenticated users cannot view agent logs', function () {
    $response = $this->get(route('agent-logs.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view paginated agent logs audit trail', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $partner = DonationPartner::factory()->create();

    // Create 3 diverse logs
    AgentLog::factory()->create([
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
        'reasoning' => 'Markdown of 40% applied due to 48h expiration window.',
        'status' => 'success',
    ]);

    AgentLog::factory()->forDonation($partner)->create([
        'product_id' => $product->id,
        'reasoning' => 'Reserved donation for verified charity partner.',
        'status' => 'success',
    ]);

    AgentLog::factory()->create([
        'product_id' => null,
        'action_type' => 'alert_sent',
        'reasoning' => 'Inventory daemon broadcast alert.',
        'status' => 'success',
    ]);

    $response = $this->actingAs($this->user)->get(route('agent-logs.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AgentLogs/Index')
            ->has('logs.data', 3)
            ->has('stats')
            ->where('stats.total_logs', 3)
            ->where('stats.discounts_count', 1)
            ->where('stats.donations_count', 1)
            ->where('stats.alerts_count', 1)
            ->has('filters')
            ->has('partners')
        );
});

test('agent logs can be filtered by action type and status', function () {
    $product = Product::factory()->create();

    AgentLog::factory()->create([
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
        'status' => 'success',
    ]);

    AgentLog::factory()->create([
        'product_id' => $product->id,
        'action_type' => 'donation_requested',
        'status' => 'failed',
    ]);

    // Filter by discount_applied
    $response = $this->actingAs($this->user)->get(route('agent-logs.index', [
        'action_type' => 'discount_applied',
    ]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action_type', 'discount_applied')
        );

    // Filter by failed status
    $responseFailed = $this->actingAs($this->user)->get(route('agent-logs.index', [
        'status' => 'failed',
    ]));

    $responseFailed->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.status', 'failed')
        );
});

test('agent logs search matches reasoning, product sku and name', function () {
    $p1 = Product::factory()->create(['name' => 'Organic Almond Milk', 'sku' => 'ECL-ALM-101']);
    $p2 = Product::factory()->create(['name' => 'Whole Wheat Bread', 'sku' => 'ECL-BRD-202']);

    AgentLog::factory()->create([
        'product_id' => $p1->id,
        'reasoning' => 'Almond milk markdown due to low turnover.',
    ]);

    AgentLog::factory()->create([
        'product_id' => $p2->id,
        'reasoning' => 'Bread expiry donation dispatch.',
    ]);

    $response = $this->actingAs($this->user)->get(route('agent-logs.index', [
        'search' => 'ECL-ALM-101',
    ]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.product.sku', 'ECL-ALM-101')
        );
});

test('admin can execute manual override to revert automated discount', function () {
    $product = Product::factory()->create([
        'status' => 'discounted',
        'original_price' => 10.00,
        'discount_price' => 6.00,
    ]);

    $log = AgentLog::factory()->create([
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
        'status' => 'success',
        'payload' => [
            'discount_percentage' => 40,
            'new_discount_price' => 6.00,
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('agent-logs.override', $log), [
        'override_action' => 'revert',
        'reason' => 'Inventory count showed fresh stock arrived from supplier.',
    ]);

    $response->assertRedirect()
        ->assertSessionHas('success');

    // Product should be reverted to active with null discount price
    expect($product->fresh()->status)->toBe('active')
        ->and($product->fresh()->discount_price)->toBeNull();

    // Original log should be flagged as overridden
    expect($log->fresh()->payload['is_overridden'])->toBeTrue()
        ->and($log->fresh()->payload['override_reason'])->toBe('Inventory count showed fresh stock arrived from supplier.');

    // A new manual override log must be recorded
    $overrideLog = AgentLog::where('action_type', 'manual_override')->first();
    expect($overrideLog)->not->toBeNull()
        ->and($overrideLog->product_id)->toBe($product->id)
        ->and($overrideLog->payload['override_action'])->toBe('revert');
});

test('admin can execute manual override to modify discount percentage', function () {
    $product = Product::factory()->create([
        'status' => 'discounted',
        'original_price' => 10.00,
        'discount_price' => 5.00,
    ]);

    $log = AgentLog::factory()->create([
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
        'status' => 'success',
    ]);

    $response = $this->actingAs($this->user)->post(route('agent-logs.override', $log), [
        'override_action' => 'modify_discount',
        'discount_percentage' => 30,
        'reason' => 'Manager approved 30% instead of 50% markdown.',
    ]);

    $response->assertRedirect()
        ->assertSessionHas('success');

    // Product should have 30% discount applied ($7.00)
    expect($product->fresh()->status)->toBe('discounted')
        ->and((float) $product->fresh()->discount_price)->toBe(7.00);
});

test('admin can trigger simulated ai evaluation scan', function () {
    Product::factory()->create([
        'status' => 'active',
        'expiration_date' => now()->addDays(2)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)->post(route('agent-logs.simulate'));

    $response->assertRedirect()
        ->assertSessionHas('success');
});
