<?php

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

test('a clear vision result offers an explicit donation and preserves other batch stock', function () {
    config()->set('services.ninerouter.api_key', 'test-router-key');
    config()->set('services.ninerouter.vision_model', 'openrouter/nvidia/nemotron-3.5-super-vl:free');
    Queue::fake();
    Http::fake([
        'http://127.0.0.1:20128/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'donation_eligible' => true,
                'confidence' => 0.93,
                'condition' => 'acceptable',
                'reasoning' => 'Buah tampak matang dengan sedikit memar dan tidak terlihat berjamur.',
                'safety_note' => 'Periksa bau dan suhu penyimpanan secara manual sebelum diserahkan.',
            ])]]],
        ]),
    ]);

    $product = Product::factory()->create(['quantity' => 40]);
    $batch = ProductBatch::factory()->create([
        'product_id' => $product->id,
        'batch_code' => 'VISION-DONATE-01',
        'quantity_received' => 12,
        'quantity_available' => 12,
        'expiration_date' => now()->addDay()->toDateString(),
    ]);
    ProductBatch::factory()->create([
        'product_id' => $product->id,
        'batch_code' => 'VISION-KEEP-02',
        'quantity_received' => 28,
        'quantity_available' => 28,
        'expiration_date' => now()->addDays(12)->toDateString(),
    ]);
    $partner = DonationPartner::factory()->create(['telegram_chat_id' => '123456789']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('simulation.index'))
        ->post(route('simulation.vision.analyze'), [
            'batch_id' => $batch->id,
            'photo' => UploadedFile::fake()->image('produce.jpg'),
        ])
        ->assertRedirect(route('simulation.index'));

    expect($batch->fresh()->quantity_available)->toBe(12)
        ->and($product->fresh()->quantity)->toBe(40)
        ->and(AgentLog::query()->count())->toBe(0);

    $this->get(route('simulation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Simulation/Index')
            ->where('visionResult.donation_eligible', true)
            ->where('visionResult.product_name', $product->name));

    $reviews = session('simulation_vision_reviews');
    $reviewToken = array_key_first($reviews);
    expect($reviewToken)->not->toBeNull();

    $this->post(route('simulation.vision.donate'), [
        'review_token' => $reviewToken,
        'partner_id' => $partner->id,
    ])->assertRedirect(route('simulation.index'))
        ->assertSessionHas('success');

    expect($batch->fresh()->quantity_available)->toBe(0)
        ->and($batch->fresh()->quantity_donated)->toBe(12)
        ->and($batch->fresh()->status)->toBe('donated')
        ->and($product->fresh()->quantity)->toBe(28)
        ->and(AgentLog::query()->where('action_type', 'donation_requested')->where('status', 'success')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event_type', 'donation_completed')->count())->toBe(3)
        ->and(NotificationDelivery::query()->where('event_type', 'donation_completed')->where('channel', 'email')->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('event_type', 'donation_completed')->where('channel', 'telegram')->count())->toBe(1);

    Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:20128/v1/chat/completions'
        && $request['messages'][1]['content'][1]['type'] === 'image_url');
});

test('uncertain vision results require manual review and cannot be donated', function () {
    config()->set('services.ninerouter.api_key', 'test-router-key');
    Http::fake([
        '*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'donation_eligible' => true,
                'confidence' => 0.4,
                'condition' => 'unclear',
                'reasoning' => 'Foto buram dan kondisi tidak jelas.',
                'safety_note' => 'Minta foto ulang atau periksa manual.',
            ])]]],
        ]),
    ]);

    $product = Product::factory()->create();
    $batch = ProductBatch::factory()->create([
        'product_id' => $product->id,
        'quantity_available' => 8,
        'expiration_date' => now()->addDay()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create())
        ->from(route('simulation.index'))
        ->post(route('simulation.vision.analyze'), [
            'batch_id' => $batch->id,
            'photo' => UploadedFile::fake()->image('unclear.jpg'),
        ])
        ->assertRedirect(route('simulation.index'));

    expect($batch->fresh()->quantity_available)->toBe(8)
        ->and(session('simulation_vision_reviews', []))->toBeEmpty()
        ->and(AgentLog::query()->count())->toBe(0);
});
