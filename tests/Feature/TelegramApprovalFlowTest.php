<?php

use App\Jobs\SendDiscountApprovalNotifications;
use App\Jobs\SendDonationNotifications;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function makeApproval(Product $product, ProductBatch $batch): DiscountApproval
{
    return DiscountApproval::query()->create([
        'product_id' => $product->id,
        'product_batch_id' => $batch->id,
        'recommended_percentage' => 30,
        'recommended_price' => 70,
        'reasoning' => 'The batch expires soon.',
    ]);
}

test('telegram approval applies the ai recommendation without changing batch stock', function () {
    config([
        'services.telegram.webhook_secret' => 'local-test-secret',
        'services.telegram.bot_token' => 'local-test-token',
    ]);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $user = User::factory()->create(['role' => 'manager', 'telegram_chat_id' => '12345']);
    $product = Product::factory()->create(['original_price' => 100, 'discount_price' => null]);
    $batch = ProductBatch::factory()->for($product)->create([
        'quantity_available' => 12,
        'original_price' => 100,
        'discount_price' => null,
    ]);
    $approval = makeApproval($product, $batch);

    $response = $this->postJson('/api/v1/telegram/webhook', [
        'callback_query' => [
            'id' => 'callback-1',
            'data' => 'approve:'.$approval->id,
            'message' => ['chat' => ['id' => 12345]],
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'local-test-secret']);

    $response->assertOk()->assertJsonPath('approval_id', $approval->id);
    expect($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->approved_percentage)->toBe(30)
        ->and((float) $batch->fresh()->discount_price)->toBe(70.0)
        ->and($batch->fresh()->quantity_available)->toBe(12)
        ->and($product->fresh()->status)->toBe('discounted');
    $this->assertDatabaseHas('agent_logs', ['action_type' => 'discount_applied', 'status' => 'success']);
});

test('telegram custom discount is capped at fifty percent and records the deciding account', function () {
    config([
        'services.telegram.webhook_secret' => 'local-test-secret',
        'services.telegram.bot_token' => 'local-test-token',
    ]);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $user = User::factory()->create(['role' => 'cashier', 'telegram_chat_id' => '23456']);
    $product = Product::factory()->create(['original_price' => 200]);
    $batch = ProductBatch::factory()->for($product)->create(['original_price' => 200, 'quantity_available' => 4]);
    $approval = makeApproval($product, $batch);

    $this->postJson('/api/v1/telegram/webhook', [
        'message' => ['chat' => ['id' => 23456], 'text' => '/discount '.$approval->id.' 25'],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'local-test-secret'])->assertOk();

    expect($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->approved_percentage)->toBe(25)
        ->and((float) $approval->fresh()->approved_price)->toBe(150.0)
        ->and($approval->fresh()->decided_by)->toBe($user->id)
        ->and($batch->fresh()->quantity_available)->toBe(4);
});

test('telegram webhook rejects a missing or incorrect secret', function () {
    config(['services.telegram.webhook_secret' => 'local-test-secret']);

    $this->postJson('/api/v1/telegram/webhook', [])->assertForbidden();
});

test('telegram webhook command registers a public https endpoint without exposing its token', function () {
    config([
        'app.url' => 'https://ecoloop-demo.example',
        'services.telegram.bot_token' => 'test-token-never-print',
        'services.telegram.webhook_secret' => 'test-webhook-secret',
    ]);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
        && $request['url'] === 'https://ecoloop-demo.example/api/v1/telegram/webhook'
        && $request['secret_token'] === 'test-webhook-secret');
});

test('telegram approval only accepts linked manager or cashier accounts', function () {
    config([
        'services.telegram.webhook_secret' => 'local-test-secret',
        'services.telegram.bot_token' => 'local-test-token',
    ]);
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    User::factory()->create(['role' => 'staff', 'telegram_chat_id' => '34567']);
    $product = Product::factory()->create();
    $batch = ProductBatch::factory()->for($product)->create();
    $approval = makeApproval($product, $batch);

    $this->postJson('/api/v1/telegram/webhook', [
        'message' => ['chat' => ['id' => 34567], 'text' => '/discount '.$approval->id.' 25'],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'local-test-secret'])->assertOk();

    expect($approval->fresh()->status)->toBe('pending')
        ->and($batch->fresh()->discount_price)->toBeNull();
});

test('donation notifications are queued for the partner and manager or cashier accounts', function () {
    Queue::fake([SendDonationNotifications::class]);

    $partner = DonationPartner::factory()->create(['telegram_chat_id' => '98765']);
    $manager = User::factory()->create(['role' => 'manager', 'telegram_chat_id' => '11111']);
    $cashier = User::factory()->create(['role' => 'cashier', 'telegram_chat_id' => null]);
    User::factory()->create(['role' => 'viewer', 'telegram_chat_id' => '22222']);
    $product = Product::factory()->create();
    $batch = ProductBatch::factory()->for($product)->create(['quantity_donated' => 3, 'quantity_available' => 0]);

    app(NotificationService::class)->queueDonationAlerts($partner, $product, $batch, 'Expiry threshold reached.');

    expect(NotificationDelivery::query()->where('event_type', 'donation_completed')->count())->toBe(5);
    $this->assertDatabaseHas('notification_deliveries', ['recipient' => $partner->email, 'channel' => 'email']);
    $this->assertDatabaseHas('notification_deliveries', ['recipient' => '98765', 'channel' => 'telegram']);
    $this->assertDatabaseHas('notification_deliveries', ['recipient' => $manager->email, 'channel' => 'email']);
    $this->assertDatabaseHas('notification_deliveries', ['recipient' => '11111', 'channel' => 'telegram']);
    $this->assertDatabaseHas('notification_deliveries', ['recipient' => $cashier->email, 'channel' => 'email']);
    $this->assertDatabaseMissing('notification_deliveries', ['recipient' => '22222']);
    Queue::assertPushedTimes(SendDonationNotifications::class, 5);
});

test('sending a queued donation email marks its delivery and audit log sent', function () {
    Mail::fake();
    $delivery = NotificationDelivery::query()->create([
        'event_type' => 'donation_completed',
        'channel' => 'email',
        'recipient' => 'partner@example.test',
        'dedupe_key' => 'test:donation:email',
        'subject' => 'Donation completed',
        'payload' => ['product_id' => null, 'body' => 'Donation test'],
    ]);

    (new SendDonationNotifications($delivery->id))->handle(app(TelegramBotService::class));

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->attempts)->toBe(1);
    $this->assertDatabaseHas('agent_logs', ['action_type' => 'alert_sent', 'status' => 'success']);
});

test('new discount approval notifications include approve and reject telegram buttons', function () {
    Queue::fake([SendDiscountApprovalNotifications::class]);
    User::factory()->create(['role' => 'manager', 'telegram_chat_id' => '45678']);
    $product = Product::factory()->create();
    $batch = ProductBatch::factory()->for($product)->create();
    $approval = makeApproval($product, $batch);

    app(NotificationService::class)->queueDiscountApproval($approval);

    $delivery = NotificationDelivery::query()->where('event_type', 'discount_approval')->firstOrFail();
    expect($delivery->payload['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('approve:'.$approval->id)
        ->and($delivery->payload['reply_markup']['inline_keyboard'][0][1]['callback_data'])->toBe('reject:'.$approval->id);
    Queue::assertPushed(SendDiscountApprovalNotifications::class);
});
