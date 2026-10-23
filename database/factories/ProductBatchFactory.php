<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBatch>
 */
class ProductBatchFactory extends Factory
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
            'batch_code' => fake()->unique()->bothify('LOT-########'),
            'quantity_received' => 20,
            'quantity_available' => 20,
            'quantity_donated' => 0,
            'quantity_sold' => 0,
            'expiration_date' => now()->addDays(10)->toDateString(),
            'original_price' => fake()->randomFloat(2, 1, 100),
            'discount_price' => null,
            'status' => 'active',
            'source' => 'manual',
            'source_reference' => null,
        ];
    }
}
