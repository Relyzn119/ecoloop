<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page when accessing auth dashboard route', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('public users can visit the dashboard at root path', function () {
    $response = $this->get('/');
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('metrics')
        ->has('recent_agent_logs')
        ->has('urgent_products')
    );
});

test('authenticated users can visit the dashboard route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('metrics')
    );
});

test('dashboard accurately computes circular economy metrics', function () {
    $category = Category::factory()->create(['name' => 'Bakery', 'slug' => 'bakery']);

    // 1. Expiring soon active product (in 3 days) -> counts in total_expiring_soon
    Product::factory()->create([
        'category_id' => $category->id,
        'status' => 'active',
        'quantity' => 10,
        'original_price' => 5.00,
        'expiration_date' => now()->addDays(3)->toDateString(),
    ]);

    // 2. Product expiring in 10 days (> 7 days) -> should NOT count in total_expiring_soon
    Product::factory()->create([
        'category_id' => $category->id,
        'status' => 'active',
        'quantity' => 15,
        'original_price' => 8.00,
        'expiration_date' => now()->addDays(10)->toDateString(),
    ]);

    // 3. Discounted product (food saved)
    Product::factory()->create([
        'category_id' => $category->id,
        'status' => 'discounted',
        'quantity' => 20,
        'original_price' => 10.00,
        'discount_price' => 5.00,
        'expiration_date' => now()->addDays(2)->toDateString(),
    ]);

    // 4. Reserved donation product (food saved)
    Product::factory()->create([
        'category_id' => $category->id,
        'status' => 'reserved_donation',
        'quantity' => 12,
        'original_price' => 15.00,
        'discount_price' => null,
        'expiration_date' => now()->addDays(1)->toDateString(),
    ]);

    // Partner & Agent Log
    $partner = DonationPartner::factory()->create([
        'name' => 'Metro Care Pantry',
        'is_active' => true,
    ]);

    AgentLog::create([
        'product_id' => null,
        'action_type' => 'donation_requested',
        'reasoning' => 'Allocated surplus produce to Metro Care Pantry',
        'payload' => [
            'partner_id' => $partner->id,
            'partner_name' => $partner->name,
        ],
        'status' => 'success',
    ]);

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('metrics.total_expiring_soon', 1)
        ->where('metrics.food_saved_kg', 32) // 20 + 12
        ->where('metrics.co2_emission_reduced_kg', 80) // 32 * 2.5
        ->has('metrics.financial_loss_prevented')
        ->has('metrics.financial_loss_prevented_formatted')
        ->has('recent_agent_logs.0', fn (Assert $log) => $log
            ->where('action_type', 'donation_requested')
            ->where('partner_name', 'Metro Care Pantry')
            ->where('partner.id', $partner->id)
            ->etc()
        )
    );
});
