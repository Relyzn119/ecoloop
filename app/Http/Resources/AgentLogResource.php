<?php

namespace App\Http\Resources;

use App\Models\AgentLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentLog
 */
class AgentLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $partner = $this->partner ?? null;
        $partnerData = null;

        if ($partner) {
            $partnerData = [
                'id' => $partner->id,
                'name' => $partner->name,
                'contact_person' => $partner->contact_person,
                'email' => $partner->email,
                'phone' => $partner->phone,
            ];
        } elseif (!empty($this->payload['partner_name']) || !empty($this->payload['recipient'])) {
            $partnerData = [
                'id' => $this->payload['partner_id'] ?? null,
                'name' => $this->payload['partner_name'] ?? $this->payload['recipient'] ?? 'Partner',
                'contact_person' => null,
                'email' => $this->payload['partner_email'] ?? null,
                'phone' => null,
            ];
        }

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'partner_id' => $this->partner_id,
            'action_type' => $this->action_type,
            'reasoning' => $this->reasoning,
            'payload' => $this->payload,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans() ?? 'Just now',
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'category' => $this->product->category?->name ?? 'General',
                'original_price' => (float) $this->product->original_price,
                'discount_price' => $this->product->discount_price !== null ? (float) $this->product->discount_price : null,
                'status' => $this->product->status,
                'expiration_date' => $this->product->expiration_date?->toDateString(),
            ] : null),
            'partner' => $partnerData,
        ];
    }
}
