<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originalPrice = fake()->randomFloat(2, 5, 120);

        return [
            'category_id' => Category::factory(),
            'sku' => 'ECL-'.strtoupper(fake()->unique()->bothify('??-####')),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(8),
            'quantity' => fake()->numberBetween(5, 100),
            'unit' => fake()->randomElement(['kg', 'pcs', 'liters', 'packs', 'crates']),
            'original_price' => $originalPrice,
            'discount_price' => null,
            'expiration_date' => now()->addDays(fake()->numberBetween(1, 30))->toDateString(),
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the product is expiring soon.
     */
    public function expiringSoon(int $days = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'expiration_date' => now()->addDays($days)->toDateString(),
        ]);
    }

    /**
     * Indicate that the product has been discounted.
     */
    public function discounted(float $discountPercent = 0.30): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'discounted',
            'discount_price' => round($attributes['original_price'] * (1 - $discountPercent), 2),
        ]);
    }

    /**
     * Indicate that the product is reserved for donation.
     */
    public function reservedDonation(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reserved_donation',
        ]);
    }

    /**
     * Indicate that the product has been donated.
     */
    public function donated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'donated',
        ]);
    }
}
