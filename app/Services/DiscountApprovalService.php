<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\DiscountApproval;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscountApprovalService
{
    public function decide(int $approvalId, User $user, string $decision, ?int $percentage = null): DiscountApproval
    {
        if (! in_array($user->role, ['manager', 'cashier'], true)) {
            throw new AuthorizationException('Only a manager or cashier may decide discount approvals.');
        }

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        $approval = DB::transaction(function () use ($approvalId, $user, $decision, $percentage): DiscountApproval {
            $approval = DiscountApproval::query()->lockForUpdate()->findOrFail($approvalId);
            if ($approval->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'This discount request has already been decided.']);
            }

            $percentage ??= $decision === 'approve' ? $approval->recommended_percentage : null;
            if ($decision === 'approve' && ($percentage === null || $percentage < 1 || $percentage > 50)) {
                throw ValidationException::withMessages(['discount_percentage' => 'Discount must be between 1% and 50%.']);
            }

            $batch = ProductBatch::query()->lockForUpdate()->findOrFail($approval->product_batch_id);
            if ($batch->quantity_available < 1) {
                throw ValidationException::withMessages(['approval' => 'This batch no longer has stock available.']);
            }

            $fields = [
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $user->id,
                'decided_at' => now(),
            ];
            $newPrice = null;

            if ($decision === 'approve') {
                $newPrice = round((float) $batch->original_price * (1 - ($percentage / 100)), 2);
                $fields['approved_percentage'] = $percentage;
                $fields['approved_price'] = $newPrice;
                $batch->update(['status' => 'discounted', 'discount_price' => $newPrice]);
            }

            $approval->update($fields);
            $this->refreshProductSummary($approval->product_id);

            AgentLog::query()->create([
                'product_id' => $approval->product_id,
                'action_type' => $decision === 'approve' ? 'discount_applied' : 'discount_rejected',
                'reasoning' => sprintf('Discount request %s by %s.', $decision === 'approve' ? 'approved' : 'rejected', $user->name),
                'payload' => [
                    'approval_id' => $approval->id,
                    'batch_id' => $batch->id,
                    'decision' => $decision,
                    'discount_percentage' => $percentage,
                    'discount_price' => $newPrice,
                    'decided_by' => $user->id,
                    'channel' => 'telegram',
                ],
                'status' => 'success',
            ]);

            return $approval->fresh(['product', 'batch']);
        });

        if ($approval->status === 'approved') {
            app(NotificationService::class)->queueDiscountOffers($approval);
            app(N8nFinalEventService::class)->queue(
                'discount.approved',
                'discount-approval:'.$approval->id,
                [
                    'approval_id' => $approval->id,
                    'product_id' => $approval->product_id,
                    'batch_id' => $approval->product_batch_id,
                    'discount_percentage' => $approval->approved_percentage,
                    'discount_price' => $approval->approved_price,
                    'decided_by' => $approval->decided_by,
                ]
            );
        }

        return $approval;
    }

    private function refreshProductSummary(int $productId): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $available = $product->batches()->where('quantity_available', '>', 0)->get();
        $allDiscounted = $available->isNotEmpty() && $available->every(fn (ProductBatch $batch): bool => $batch->status === 'discounted');

        $product->update([
            'quantity' => (int) $available->sum('quantity_available'),
            'expiration_date' => $available->min('expiration_date') ?? $product->expiration_date,
            'status' => $available->isEmpty() ? 'donated' : ($allDiscounted ? 'discounted' : 'active'),
            'discount_price' => $allDiscounted && $available->pluck('discount_price')->unique()->count() === 1
                ? $available->first()->discount_price
                : null,
        ]);
    }
}
