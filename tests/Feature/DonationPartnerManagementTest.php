<?php

use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login when accessing donation partner routes', function () {
    $this->get('/donation-partners')->assertRedirect('/login');
    $this->post('/donation-partners', [])->assertRedirect('/login');
});

test('authenticated user can view donation partners index with stats', function () {
    $user = User::factory()->create();
    $activePartner = DonationPartner::factory()->create([
        'name' => 'Food Bank Indonesia',
        'is_active' => true,
    ]);
    $inactivePartner = DonationPartner::factory()->create([
        'name' => 'Community Shelter',
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)->get('/donation-partners');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Partners/Index')
        ->has('partners.data', 2)
        ->has('stats')
        ->where('stats.total_partners', 2)
        ->where('stats.active_partners', 1)
        ->where('stats.inactive_partners', 1)
        ->where('partners.data.0.name', 'Community Shelter')
        ->where('partners.data.1.name', 'Food Bank Indonesia')
    );
});

test('user can search and filter donation partners', function () {
    $user = User::factory()->create();

    DonationPartner::factory()->create([
        'name' => 'Yayasan Kasih Anak',
        'contact_person' => 'Budi Santoso',
        'is_active' => true,
    ]);

    DonationPartner::factory()->create([
        'name' => 'Panti Asuhan Sejahtera',
        'contact_person' => 'Dewi Lestari',
        'is_active' => false,
    ]);

    // Search by query
    $searchResponse = $this->actingAs($user)->get('/donation-partners?search=Yayasan');
    $searchResponse->assertInertia(fn (Assert $page) => $page
        ->has('partners.data', 1)
        ->where('partners.data.0.name', 'Yayasan Kasih Anak')
    );

    // Filter by active status
    $activeResponse = $this->actingAs($user)->get('/donation-partners?status=active');
    $activeResponse->assertInertia(fn (Assert $page) => $page
        ->has('partners.data', 1)
        ->where('partners.data.0.name', 'Yayasan Kasih Anak')
    );

    // Filter by inactive status
    $inactiveResponse = $this->actingAs($user)->get('/donation-partners?status=inactive');
    $inactiveResponse->assertInertia(fn (Assert $page) => $page
        ->has('partners.data', 1)
        ->where('partners.data.0.name', 'Panti Asuhan Sejahtera')
    );
});

test('authenticated user can store a new donation partner', function () {
    $user = User::factory()->create();

    $payload = [
        'name' => 'Bandung Food Rescue',
        'contact_person' => 'Rizky Pratama',
        'phone' => '+6281234567890',
        'email' => 'logistics@bandungfoodrescue.org',
        'address' => 'Jl. Asia Afrika No. 12, Bandung',
        'is_active' => true,
    ];

    $response = $this->actingAs($user)->post('/donation-partners', $payload);

    $response->assertRedirect();
    $this->assertDatabaseHas('donation_partners', [
        'name' => 'Bandung Food Rescue',
        'contact_person' => 'Rizky Pratama',
        'email' => 'logistics@bandungfoodrescue.org',
        'is_active' => true,
    ]);
});

test('store donation partner enforces validation rules', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/donation-partners', []);

    $response->assertSessionHasErrors([
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
    ]);
});

test('authenticated user can update an existing donation partner', function () {
    $user = User::factory()->create();
    $partner = DonationPartner::factory()->create([
        'name' => 'Old Partner Name',
    ]);

    $payload = [
        'name' => 'Updated Food Hub',
        'contact_person' => 'Jane Doe',
        'phone' => '+628999888777',
        'email' => 'jane@foodhub.org',
        'address' => 'Jl. Gatot Subroto No. 88, Jakarta Selatan',
        'is_active' => true,
    ];

    $response = $this->actingAs($user)->put("/donation-partners/{$partner->id}", $payload);

    $response->assertRedirect();
    $this->assertDatabaseHas('donation_partners', [
        'id' => $partner->id,
        'name' => 'Updated Food Hub',
        'contact_person' => 'Jane Doe',
    ]);
});

test('authenticated user can toggle partner active status', function () {
    $user = User::factory()->create();
    $partner = DonationPartner::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->post("/donation-partners/{$partner->id}/toggle-status");

    $response->assertRedirect();
    $partner->refresh();
    expect($partner->is_active)->toBeFalse();

    // Toggle back
    $this->actingAs($user)->post("/donation-partners/{$partner->id}/toggle-status");
    $partner->refresh();
    expect($partner->is_active)->toBeTrue();
});

test('authenticated user can delete a donation partner', function () {
    $user = User::factory()->create();
    $partner = DonationPartner::factory()->create();

    $response = $this->actingAs($user)->delete("/donation-partners/{$partner->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('donation_partners', [
        'id' => $partner->id,
    ]);
});

test('notification service dispatches donation alert and logs to agent_logs', function () {
    $partner = DonationPartner::factory()->create([
        'name' => 'Care Meals Jakarta',
        'contact_person' => 'Agus Priyono',
        'phone' => '+628111222333',
        'email' => 'contact@caremeals.id',
    ]);

    $product = Product::factory()->create([
        'name' => 'Artisan Bread Rolls',
        'sku' => 'BREAD-01',
        'quantity' => 50,
        'unit' => 'pcs',
        'expiration_date' => now()->addDays(2)->toDateString(),
    ]);

    $service = new NotificationService;
    $result = $service->sendDonationAlert($partner, $product, 'Urgent shelf-life clearance');

    expect($result['success'])->toBeTrue()
        ->and($result['payload']['channels']['whatsapp']['to'])->toBe($partner->phone)
        ->and($result['payload']['channels']['whatsapp']['body'])->toContain('Artisan Bread Rolls')
        ->and($result['payload']['channels']['email']['to'])->toBe($partner->email);

    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'alert_sent',
        'status' => 'success',
    ]);
});

test('notification service sends http post to n8n webhook when configured', function () {
    Config::set('services.n8n.webhook_url', 'https://n8n.ecoloop.test/webhook/donation-alert');

    $partner = DonationPartner::factory()->create([
        'name' => 'Surplus Angels',
        'phone' => '+628555444333',
    ]);

    $product = Product::factory()->create([
        'name' => 'Fresh Pasteurized Milk',
        'quantity' => 20,
        'unit' => 'liters',
    ]);

    Http::fake([
        'https://n8n.ecoloop.test/webhook/donation-alert' => Http::response(['dispatched' => true], 200),
    ]);

    $service = new NotificationService;
    $result = $service->sendDonationAlert($partner, $product, 'Automated allocation via n8n');

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe('success');

    Http::assertSent(function ($request) use ($partner, $product) {
        return $request->url() === 'https://n8n.ecoloop.test/webhook/donation-alert'
            && $request['recipient']['name'] === $partner->name
            && $request['donation']['name'] === $product->name;
    });

    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'alert_sent',
        'status' => 'success',
    ]);
});

test('reserving donation records the database action without reporting a delivery before donation is complete', function () {
    $partner = DonationPartner::factory()->create(['name' => 'Metro Care Pantry']);
    $product = Product::factory()->create(['status' => 'active', 'quantity' => 15]);

    $inventoryService = new InventoryService;
    $inventoryService->reserveForDonation($product->id, $partner->id, 'Allocated to Metro Care Pantry');

    $product->refresh();
    expect($product->status)->toBe('reserved_donation');

    // A pending reservation is not yet a completed donation and sends no completion notification.
    $this->assertDatabaseHas('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'donation_requested',
    ]);

    $this->assertDatabaseMissing('agent_logs', [
        'product_id' => $product->id,
        'action_type' => 'alert_sent',
    ]);
});
