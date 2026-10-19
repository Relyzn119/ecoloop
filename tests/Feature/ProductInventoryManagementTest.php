<?php

use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login when accessing inventory routes', function () {
    $this->get('/inventory')->assertRedirect('/login');
    $this->get('/inventory/create')->assertRedirect('/login');
    $this->post('/inventory', [])->assertRedirect('/login');
});

test('authenticated users can view inventory index with products and categories', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Dairy & Chilled']);

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Greek Yogurt 500g',
        'sku' => 'DAIRY-001',
        'quantity' => 15,
        'unit' => 'pcs',
        'original_price' => 35000,
        'expiration_date' => now()->addDays(2)->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/inventory');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Index')
        ->has('products.data', 1)
        ->has('categories')
        ->has('donation_partners')
        ->has('stats')
        ->where('products.data.0.sku', 'DAIRY-001')
        ->where('products.data.0.urgency', 'critical')
    );
});

test('inventory index correctly classifies shelf life urgency levels', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    // Critical: <= 2 days
    Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'CRIT-01',
        'expiration_date' => now()->addDays(1)->toDateString(),
    ]);

    // Warning: 3 - 5 days
    Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'WARN-01',
        'expiration_date' => now()->addDays(4)->toDateString(),
    ]);

    // Safe: > 5 days
    Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'SAFE-01',
        'expiration_date' => now()->addDays(10)->toDateString(),
    ]);

    $response = $this->actingAs($user)->get('/inventory');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Index')
        ->has('products.data', 3)
    );
});

test('inventory index supports filtering by category, status, and urgency', function () {
    $user = User::factory()->create();
    $catA = Category::factory()->create(['name' => 'Bakery']);
    $catB = Category::factory()->create(['name' => 'Beverages']);

    Product::factory()->create([
        'category_id' => $catA->id,
        'name' => 'Croissant',
        'status' => 'active',
        'expiration_date' => now()->addDays(1)->toDateString(),
    ]);

    Product::factory()->create([
        'category_id' => $catB->id,
        'name' => 'Cold Brew Coffee',
        'status' => 'discounted',
        'expiration_date' => now()->addDays(8)->toDateString(),
    ]);

    // Filter by Category
    $resCat = $this->actingAs($user)->get('/inventory?category='.$catA->id);
    $resCat->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Croissant')
    );

    // Filter by Status
    $resStatus = $this->actingAs($user)->get('/inventory?status=discounted');
    $resStatus->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Cold Brew Coffee')
    );

    // Filter by Urgency
    $resUrgency = $this->actingAs($user)->get('/inventory?urgency=critical');
    $resUrgency->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Croissant')
    );
});

test('authenticated user can view product creation form', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Fresh Produce']);

    $response = $this->actingAs($user)->get('/inventory/create');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Form')
        ->where('product', null)
        ->has('categories', 1)
    );
});

test('authenticated user can store a new product in inventory', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $payload = [
        'category_id' => $category->id,
        'sku' => 'TEST-SKU-999',
        'name' => 'Artisan Baguette',
        'description' => 'Crisp organic sourdough baguette',
        'quantity' => 25,
        'unit' => 'pcs',
        'original_price' => 20000,
        'discount_price' => null,
        'expiration_date' => now()->addDays(3)->toDateString(),
        'status' => 'active',
    ];

    $response = $this->actingAs($user)->post('/inventory', $payload);

    $response->assertRedirect('/inventory');
    $this->assertDatabaseHas('products', [
        'sku' => 'TEST-SKU-999',
        'name' => 'Artisan Baguette',
        'quantity' => 25,
    ]);
});

test('store product enforces validation constraints', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/inventory', []);

    $response->assertSessionHasErrors([
        'category_id',
        'sku',
        'name',
        'quantity',
        'unit',
        'original_price',
        'expiration_date',
        'status',
    ]);
});

test('authenticated user can view edit product form', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->get("/inventory/{$product->id}/edit");

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Form')
        ->where('product.id', $product->id)
        ->where('product.sku', $product->sku)
    );
});

test('authenticated user can update an existing product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'name' => 'Old Product Name',
        'quantity' => 5,
    ]);

    $payload = [
        'category_id' => $product->category_id,
        'sku' => $product->sku,
        'name' => 'Updated Product Name',
        'description' => 'Updated description',
        'quantity' => 30,
        'unit' => $product->unit,
        'original_price' => 45000,
        'discount_price' => null,
        'expiration_date' => now()->addDays(5)->toDateString(),
        'status' => 'active',
    ];

    $response = $this->actingAs($user)->put("/inventory/{$product->id}", $payload);

    $response->assertRedirect('/inventory');
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Product Name',
        'quantity' => 30,
    ]);
});

test('authenticated user can delete a product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->delete("/inventory/{$product->id}");

    $response->assertRedirect('/inventory');
    $this->assertDatabaseMissing('products', [
        'id' => $product->id,
    ]);
});

test('authenticated user can apply manual markdown discount to product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'original_price' => 50000,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post("/inventory/{$product->id}/discount", [
        'discount_percentage' => 40,
        'reason' => 'Operator manual markdown discount',
    ]);

    $response->assertRedirect();
    $product->refresh();

    expect($product->status)->toBe('discounted');
    expect((float) $product->discount_price)->toBe(30000.0);

    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'discount_applied',
    ]);
});

test('authenticated user can reserve product for donation to partner', function () {
    $user = User::factory()->create();
    $partner = DonationPartner::factory()->create(['name' => 'Food Bank Jakarta']);
    $product = Product::factory()->create([
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post("/inventory/{$product->id}/donate", [
        'partner_id' => $partner->id,
        'reason' => 'Surplus batch allocated to Food Bank Jakarta',
    ]);

    $response->assertRedirect();
    $product->refresh();

    expect($product->status)->toBe('reserved_donation');

    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'donation_requested',
    ]);
});
