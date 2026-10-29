<?php

namespace App\Services;

use App\Models\InventorySyncSetting;
use App\Models\ProductAutomationPolicy;

class ProductAutomationPolicyService
{
    /** @return array{auto_donation_enabled: bool, auto_discount_enabled: bool, auto_donation_prealert_enabled: bool, activated_by: ?int} */
    public function forProduct(int $productId, ?InventorySyncSetting $settings = null): array
    {
        $settings ??= InventorySyncSetting::query()->findOrFail(1);
        $override = ProductAutomationPolicy::query()->where('product_id', $productId)->first();

        return [
            'auto_donation_enabled' => $override?->auto_donation_enabled ?? (bool) $settings->auto_donation_enabled,
            'auto_discount_enabled' => $override?->auto_discount_enabled ?? (bool) $settings->auto_discount_enabled,
            'auto_donation_prealert_enabled' => $override?->auto_donation_prealert_enabled ?? (bool) $settings->auto_donation_prealert_enabled,
            'activated_by' => $override?->updated_by ?? $settings->auto_decision_activated_by,
        ];
    }
}
