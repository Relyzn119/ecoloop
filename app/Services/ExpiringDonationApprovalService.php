<?php

namespace App\Services;

use App\Models\DonationPartner;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\TelegramAiAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpiringDonationApprovalService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Notify managers about batches due tomorrow/today and process pre-approved donations. */
    public function scan(): array
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $batches = ProductBatch::query()
            ->with(['product.category'])
            ->where('quantity_available', '>', 0)
            ->whereIn('status', ['active', 'discounted'])
            ->whereBetween('expiration_date', [$today->toDateString(), $today->copy()->addDays(3)->toDateString()])
            ->orderBy('expiration_date')
            ->get();

        $actions = TelegramAiAction::query()
            ->where('action_type', 'donation_batch')
            ->get()
            ->groupBy(fn (TelegramAiAction $action): string => (string) ($action->payload['batch_id'] ?? ''));

        $created = 0;
        $notified = 0;
        $executed = 0;
        foreach ($batches as $batch) {
            $daysRemaining = $today->diffInDays($batch->expiration_date->copy()->startOfDay(), false);
            $batchActions = $actions->get((string) $batch->id, collect());

            if ($batchActions->isEmpty() && $daysRemaining <= 3) {
                $created += $this->createApprovalForBatch($batch, $daysRemaining === 3);

                continue;
            }

            if ($daysRemaining > 2) {
                continue;
            }

            foreach ($batchActions as $action) {
                $currentAction = TelegramAiAction::query()->find($action->id);
                if (! $currentAction || $currentAction->status !== $action->status) {
                    continue;
                }
                $action = $currentAction;

                if ($action->status === 'scheduled') {
                    $this->completeScheduledDonation($action);
                    $executed++;

                    continue;
                }

                if ($action->status === 'deferred'
                    || $action->status === 'awaiting_quantity'
                    || ($action->status === 'pending' && ($action->payload['stage'] ?? null) === 'prealert')) {
                    $payload = $action->payload ?? [];
                    $payload['stage'] = 'eligible';
                    $payload['notification_date'] = $today->toDateString();
                    $action->update(['status' => 'pending', 'payload' => $payload]);
                    $this->notifications->queueDonationApproval($action->fresh(), $batch, false);
                    $notified++;

                    continue;
                }

                if ($action->status === 'pending'
                    && ($action->payload['notification_date'] ?? null) !== $today->toDateString()) {
                    $payload = $action->payload ?? [];
                    $payload['notification_date'] = $today->toDateString();
                    $action->update(['payload' => $payload]);
                    $this->notifications->queueDonationApproval($action->fresh(), $batch, false);
                    $notified++;
                }
            }
        }

        return ['batches' => $batches->count(), 'requests_created' => $created, 'reminders_sent' => $notified, 'scheduled_donations_run' => $executed];
    }

    /** Create one manager-owned Telegram action for each manager/cashier linked to Telegram. */
    public function createApprovalForBatch(ProductBatch $batch, bool $preAlert = false, ?string $reason = null, ?array $decision = null): int
    {
        $batch->loadMissing(['product.category']);
        $product = $batch->product;
        if ($batch->quantity_available < 1 || ! $product) {
            return 0;
        }

        if (TelegramAiAction::query()->where('action_type', 'donation_batch')->where('payload->batch_id', $batch->id)->exists()) {
            return 0;
        }

        if (! DonationPartner::query()->active()->where('partner_type', 'foundation')->exists()) {
            return 0;
        }

        $managers = User::query()->notificationRecipients()->whereNotNull('telegram_chat_id')->get();
        if ($managers->isEmpty()) {
            return 0;
        }

        $evaluationProduct = $product->replicate();
        $evaluationProduct->id = $product->id;
        $evaluationProduct->setRelation('category', $product->category);
        $evaluationProduct->quantity = $batch->quantity_available;
        $evaluationProduct->expiration_date = $preAlert
            ? now('Asia/Jakarta')->addDays(2)->toDateString()
            : $batch->expiration_date;
        $evaluationProduct->original_price = $batch->original_price;
        $evaluationProduct->discount_price = $batch->discount_price;
        $decision ??= app(AiAgentService::class)->evaluateProduct($evaluationProduct, '9router');
        $recommendedQuantity = max(1, min(
            (int) $batch->quantity_available,
            (int) ($decision['recommended_donation_quantity'] ?? $batch->quantity_available)
        ));
        $today = now('Asia/Jakarta')->toDateString();
        $expiryDate = Carbon::parse($batch->expiration_date)->toDateString();
        $eligibleOn = $preAlert ? now('Asia/Jakarta')->addDay()->toDateString() : $today;

        return DB::transaction(function () use ($batch, $product, $managers, $recommendedQuantity, $preAlert, $expiryDate, $eligibleOn, $today, $decision, $reason): int {
            $lockedBatch = ProductBatch::query()->lockForUpdate()->find($batch->id);
            if (! $lockedBatch
                || $lockedBatch->quantity_available < 1
                || TelegramAiAction::query()->where('action_type', 'donation_batch')->where('payload->batch_id', $batch->id)->exists()) {
                return 0;
            }

            foreach ($managers as $manager) {
                $action = TelegramAiAction::query()->create([
                    'id' => (string) Str::uuid(),
                    'telegram_chat_id' => $manager->telegram_chat_id,
                    'requested_by' => $manager->id,
                    'action_type' => 'donation_batch',
                    'product_id' => $product->id,
                    'payload' => [
                        'batch_id' => $batch->id,
                        'unit' => $product->unit,
                        'recommended_quantity' => $recommendedQuantity,
                        'quantity' => $recommendedQuantity,
                        'stock_mode' => 'reduce',
                        'preapproved' => false,
                        'stage' => $preAlert ? 'prealert' : 'eligible',
                        'eligible_on' => $eligibleOn,
                        'notification_date' => $today,
                        'reasoning' => $reason ?? (string) ($decision['reasoning'] ?? 'Batch memasuki jendela donasi EcoLoop.'),
                        'ai_provider' => $decision['provider'] ?? 'fallback',
                    ],
                    'status' => 'pending',
                    'expires_at' => Carbon::parse($expiryDate, 'Asia/Jakarta')->endOfDay(),
                ]);

                $this->notifications->queueDonationApproval($action, $batch, $preAlert);
            }

            return $managers->count();
        });
    }

    public function createRequestsForProduct(Product $product): int
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $batches = $product->batches()
            ->with('product.category')
            ->where('quantity_available', '>', 0)
            ->whereIn('status', ['active', 'discounted'])
            ->whereBetween('expiration_date', [$today->toDateString(), $today->copy()->addDays(3)->toDateString()])
            ->get();

        $created = 0;
        foreach ($batches as $batch) {
            $daysRemaining = $today->diffInDays($batch->expiration_date->copy()->startOfDay(), false);
            $created += $this->createApprovalForBatch($batch, $daysRemaining === 3);
        }

        return $created;
    }

    private function completeScheduledDonation(TelegramAiAction $action): void
    {
        $payload = $action->payload ?? [];
        $eligibleOn = Carbon::parse((string) ($payload['eligible_on'] ?? '1970-01-01'), 'Asia/Jakarta')->startOfDay();
        if ($eligibleOn->isFuture()) {
            return;
        }

        app(TelegramAiActionService::class)->completeScheduledDonation($action->id);
    }
}
