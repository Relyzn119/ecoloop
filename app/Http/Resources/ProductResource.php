<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $daysUntilExpiration = $this->expiration_date
            ? (int) now()->startOfDay()->diffInDays($this->expiration_date->startOfDay(), false)
            : null;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'original_price' => (float) $this->original_price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'expiration_date' => $this->expiration_date?->toDateString(),
            'days_until_expiration' => $daysUntilExpiration,
            'status' => $this->status,
            'recent_agent_logs' => AgentLogResource::collection($this->whenLoaded('agentLogs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
