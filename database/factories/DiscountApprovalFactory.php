<?php

namespace Database\Factories;

use App\Models\DiscountApproval;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountApproval>
 */
class DiscountApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_batch_id' => ProductBatch::factory(),
            'recommended_percentage' => 30,
            'recommended_price' => 70,
            'approved_percentage' => null,
            'approved_price' => null,
            'status' => 'pending',
            'reasoning' => fake()->sentence(),
            'decided_by' => null,
            'decided_at' => null,
        ];
    }
}
