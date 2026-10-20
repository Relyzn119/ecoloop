<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create(['name' => 'Dairy & Chilled']);
    $this->partner = DonationPartner::factory()->create([
        'name' => 'Food Bank Indonesia',
        'is_active' => true,
    ]);
});

test('unauthenticated users cannot view simulation sandbox', function () {
    $response = $this->get(route('simulation.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view simulation sandbox with products and config', function () {
    Product::factory()->count(3)->create([
        'category_id' => $this->category->id,
        'status' => 'active',
        'expiration_date' => now()->addDays(5)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)->get(route('simulation.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Simulation/Index')
            ->has('products', 3)
            ->has('partners', 1)
            ->has('config')
            ->where('config.has_n8n', false)
            ->has('recentLogs')
        );
});

test('triggering simulation with 1 day offset triggers urgent donation protocol and updates database', function () {
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Organic Fresh Milk 1L',
        'status' => 'active',
        'expiration_date' => now()->addDays(15)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)
        ->from(route('simulation.index'))
        ->post(route('simulation.trigger'), [
            'product_id' => $product->id,
            'date_offset' => 1,
            'provider' => 'mock',
            'dispatch_n8n' => true,
        ]);

    $response->assertRedirect(route('simulation.index'));

    $product->refresh();
    expect($product->status)->toBe('reserved_donation')
        ->and($product->expiration_date->toDateString())->toBe(now()->addDays(1)->toDateString());

    // Verify AgentLog was created
    $log = AgentLog::where('product_id', $product->id)->where('action_type', 'donation_requested')->first();
    expect($log)->not->toBeNull()
        ->and($log->action_type)->toBe('donation_requested')
        ->and($log->partner_id)->toBe($this->partner->id)
        ->and($log->status)->toBe('success');
});

test('triggering simulation with 4 days offset triggers dynamic clearance discount markdown', function () {
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Artisan Baguette',
        'original_price' => 50000,
        'discount_price' => null,
        'status' => 'active',
        'expiration_date' => now()->addDays(20)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)
        ->from(route('simulation.index'))
        ->post(route('simulation.trigger'), [
            'product_id' => $product->id,
            'date_offset' => 4,
            'provider' => 'mock',
        ]);

    $response->assertRedirect(route('simulation.index'));

    $product->refresh();
    expect($product->status)->toBe('discounted')
        ->and($product->discount_price)->toBe('35000.00')
        ->and($product->expiration_date->toDateString())->toBe(now()->addDays(4)->toDateString());

    // Verify AgentLog was written
    $log = AgentLog::where('product_id', $product->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->action_type)->toBe('discount_applied')
        ->and($log->payload['discount_percentage'])->toBe(30);
});

test('triggering simulation via JSON returns structured real-time response with n8n payload', function () {
    Http::fake([
        'https://n8n.internal.ecoloop/webhook/*' => Http::response(['received' => true], 200),
    ]);

    Config::set('services.n8n.webhook_url', 'https://n8n.internal.ecoloop/webhook/evaluate');

    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Greek Yogurt 500g',
        'status' => 'active',
        'expiration_date' => now()->addDays(10)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('simulation.trigger'), [
            'product_id' => $product->id,
            'date_offset' => 1,
            'provider' => 'mock',
            'dispatch_n8n' => true,
        ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'product' => ['id', 'sku', 'name', 'status', 'expiration_date', 'days_remaining'],
            'decision' => ['action', 'discount_percentage', 'recommended_partner_id', 'reasoning', 'provider'],
            'executed_action' => ['type', 'label', 'status', 'summary'],
            'agent_log',
            'n8n' => ['configured', 'url', 'status', 'payload'],
        ]);

    expect($response->json('decision.action'))->toBe('donate')
        ->and($response->json('n8n.status'))->toBe('dispatched_success');
});

test('user can reset product state back to 14 days active condition', function () {
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'status' => 'discounted',
        'discount_price' => 15000,
        'expiration_date' => now()->addDays(2)->toDateString(),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('simulation.reset', $product));

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'status' => 'active',
                'discount_price' => null,
                'days_remaining' => 14,
            ],
        ]);

    $product->refresh();
    expect($product->status)->toBe('active')
        ->and($product->discount_price)->toBeNull()
        ->and($product->expiration_date->toDateString())->toBe(now()->addDays(14)->toDateString());
});
