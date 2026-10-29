<?php

namespace App\Services;

use App\Models\DonationPartner;
use App\Models\InventorySyncSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\TelegramAiAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $settings = InventorySyncSetting::query()->findOrFail(1);

        $created = 0;
        $notified = 0;
        $executed = 0;
        $actionsToNotify = collect();
        $automaticSchedulesToNotify = collect();
        foreach ($batches as $batch) {
            $daysRemaining = $today->diffInDays($batch->expiration_date->copy()->startOfDay(), false);
            $batchActions = $actions->get((string) $batch->id, collect());

            if ($batchActions->isEmpty() && $daysRemaining <= 3) {
                $created += $this->createApprovalForBatch($batch, $daysRemaining === 3, queueNotification: false);
                $actionsToNotify = $actionsToNotify->merge($this->pendingActionsForBatch($batch->id));
                $automaticSchedulesToNotify = $automaticSchedulesToNotify->merge($this->automaticScheduledActionsForBatch($batch->id));

                continue;
            }

            if ($daysRemaining === 3 && $settings->auto_donation_prealert_enabled) {
                $preAlertAction = $batchActions->first(fn (TelegramAiAction $action): bool => $action->status === 'pending'
                    && ($action->payload['stage'] ?? null) === 'prealert');
                if ($preAlertAction) {
                    $payload = $preAlertAction->payload ?? [];
                    $payload['automation_policy'] = true;
                    $payload['prealert_auto'] = true;
                    $payload['stage'] = 'auto_preapproved';
                    $payload['preapproved'] = true;
                    $payload['quantity'] = (int) $batch->quantity_available;
                    $payload['recommended_quantity'] = (int) $batch->quantity_available;
                    $payload['stock_mode'] = 'reduce';
                    $payload['eligible_on'] = $today->copy()->addDay()->toDateString();
                    $preAlertAction->update(['status' => 'scheduled', 'payload' => $payload]);
                    $this->cancelSiblingActionsForBatch($batch->id, $preAlertAction->id);
                    $automaticSchedulesToNotify->push($preAlertAction->fresh());

                    continue;
                }
            }

            if ($daysRemaining > 2) {
                continue;
            }

            if ($settings->auto_donation_enabled) {
                $pendingAction = $batchActions->first(fn (TelegramAiAction $action): bool => in_array($action->status, ['pending', 'deferred', 'awaiting_quantity'], true));
                if ($pendingAction) {
                    $payload = $pendingAction->payload ?? [];
                    $payload['automation_policy'] = true;
                    $payload['prealert_auto'] = false;
                    $payload['stage'] = 'auto_preapproved';
                    $payload['preapproved'] = true;
                    $payload['quantity'] = (int) $batch->quantity_available;
                    $payload['recommended_quantity'] = (int) $batch->quantity_available;
                    $payload['stock_mode'] = 'reduce';
                    $payload['eligible_on'] = $today->toDateString();
                    $pendingAction->update(['status' => 'scheduled', 'payload' => $payload]);
                    $this->cancelSiblingActionsForBatch($batch->id, $pendingAction->id);
                    $this->completeScheduledDonation($pendingAction->id);
                    $executed++;

                    continue;
                }
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
                    $actionsToNotify->push($action->fresh());
                    $notified++;

                    continue;
                }

                if ($action->status === 'pending'
                    && ($action->payload['notification_date'] ?? null) !== $today->toDateString()) {
                    $payload = $action->payload ?? [];
                    $payload['notification_date'] = $today->toDateString();
                    $action->update(['payload' => $payload]);
                    $actionsToNotify->push($action->fresh());
                    $notified++;
                }
            }
        }

        $this->notifications->queueDonationApprovalBatch($actionsToNotify);
        $this->notifications->queueAutomaticDonationScheduleBatch($automaticSchedulesToNotify);

        return ['batches' => $batches->count(), 'requests_created' => $created, 'reminders_sent' => $notified, 'scheduled_donations_run' => $executed];
    }

    /** Create one manager-owned Telegram action for each manager/cashier linked to Telegram. */
    public function createApprovalForBatch(
        ProductBatch $batch,
        bool $preAlert = false,
        ?string $reason = null,
        ?array $decision = null,
        bool $queueNotification = true
    ): int {
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

        $settings = InventorySyncSetting::query()->findOrFail(1);
        $automationManager = $settings->auto_decision_activated_by
            ? User::query()->whereIn('role', ['business_owner', 'manager'])->whereNotNull('telegram_chat_id')->find((int) $settings->auto_decision_activated_by)
            : null;
        $automaticEnabled = $preAlert
            ? $settings->auto_donation_prealert_enabled
            : $settings->auto_donation_enabled;
        if ($automaticEnabled && $automationManager) {
            $action = $this->createAutomaticDonationSchedule($batch, $preAlert, $settings);
            if (! $action) {
                return 0;
            }
            if ($preAlert && $queueNotification) {
                $this->notifications->queueAutomaticDonationScheduleBatch([$action]);
            } elseif (! $preAlert) {
                app(TelegramAiActionService::class)->completeScheduledDonation($action->id);
            }

            return 1;
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

        return DB::transaction(function () use ($batch, $product, $managers, $recommendedQuantity, $preAlert, $expiryDate, $eligibleOn, $today, $decision, $reason, $queueNotification): int {
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

                if ($queueNotification) {
                    $this->notifications->queueDonationApproval($action, $batch, $preAlert);
                }
            }

            return $managers->count();
        });
    }

    /** @return Collection<int, TelegramAiAction> */
    public function pendingActionsForBatch(int $batchId): Collection
    {
        return TelegramAiAction::query()
            ->where('action_type', 'donation_batch')
            ->where('status', 'pending')
            ->where('payload->batch_id', $batchId)
            ->get();
    }

    /** @return Collection<int, TelegramAiAction> */
    public function automaticScheduledActionsForBatch(int $batchId): Collection
    {
        return TelegramAiAction::query()
            ->where('action_type', 'donation_batch')
            ->where('status', 'scheduled')
            ->where('payload->stage', 'auto_preapproved')
            ->where('payload->batch_id', $batchId)
            ->get();
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
        $actionsToNotify = collect();
        $automaticSchedulesToNotify = collect();
        foreach ($batches as $batch) {
            $daysRemaining = $today->diffInDays($batch->expiration_date->copy()->startOfDay(), false);
            $created += $this->createApprovalForBatch($batch, $daysRemaining === 3, queueNotification: false);
            $actionsToNotify = $actionsToNotify->merge($this->pendingActionsForBatch($batch->id));
            $automaticSchedulesToNotify = $automaticSchedulesToNotify->merge($this->automaticScheduledActionsForBatch($batch->id));
        }

        $this->notifications->queueDonationApprovalBatch($actionsToNotify);
        $this->notifications->queueAutomaticDonationScheduleBatch($automaticSchedulesToNotify);

        return $created;
    }

    private function createAutomaticDonationSchedule(ProductBatch $batch, bool $preAlert, InventorySyncSetting $settings): ?TelegramAiAction
    {
        $manager = User::query()->find((int) $settings->auto_decision_activated_by);
        if (! $manager
            || ! in_array($manager->role, ['business_owner', 'manager'], true)
            || ! filled($manager->telegram_chat_id)
            || ! $batch->product) {
            return null;
        }

        return DB::transaction(function () use ($batch, $preAlert, $manager): ?TelegramAiAction {
            $lockedBatch = ProductBatch::query()->lockForUpdate()->with('product')->find($batch->id);
            if (! $lockedBatch || $lockedBatch->quantity_available < 1
                || TelegramAiAction::query()->where('action_type', 'donation_batch')->where('payload->batch_id', $batch->id)->exists()) {
                return null;
            }

            $today = now('Asia/Jakarta')->startOfDay();
            $eligibleOn = $preAlert ? $today->copy()->addDay() : $today;
            $expiry = Carbon::parse($lockedBatch->expiration_date, 'Asia/Jakarta')->endOfDay();

            return TelegramAiAction::query()->create([
                'id' => (string) Str::uuid(),
                'telegram_chat_id' => (string) $manager->telegram_chat_id,
                'requested_by' => $manager->id,
                'action_type' => 'donation_batch',
                'product_id' => $lockedBatch->product_id,
                'payload' => [
                    'batch_id' => $lockedBatch->id,
                    'unit' => $lockedBatch->product->unit,
                    'recommended_quantity' => (int) $lockedBatch->quantity_available,
                    'quantity' => (int) $lockedBatch->quantity_available,
                    'stock_mode' => 'reduce',
                    'preapproved' => true,
                    'automation_policy' => true,
                    'prealert_auto' => $preAlert,
                    'stage' => 'auto_preapproved',
                    'eligible_on' => $eligibleOn->toDateString(),
                    'notification_date' => $today->toDateString(),
                    'reasoning' => $preAlert
                        ? 'Donasi dijadwalkan otomatis sesuai kebijakan yang dikonfirmasi Business Owner/Manager.'
                        : 'Donasi otomatis sesuai kebijakan yang dikonfirmasi Business Owner/Manager.',
                    'ai_provider' => 'ecoloop_policy',
                ],
                'status' => 'scheduled',
                'expires_at' => $expiry,
            ]);
        });
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

    private function cancelSiblingActionsForBatch(int $batchId, string $keepActionId): void
    {
        TelegramAiAction::query()
            ->where('action_type', 'donation_batch')
            ->whereKeyNot($keepActionId)
            ->where('payload->batch_id', $batchId)
            ->whereIn('status', ['pending', 'scheduled', 'deferred', 'awaiting_quantity'])
            ->update(['status' => 'cancelled', 'completed_at' => now()]);
    }
}
