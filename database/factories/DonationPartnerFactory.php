<?php

namespace Database\Factories;

use App\Models\DonationPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationPartner>
 */
class DonationPartnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Food Relief',
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the partner is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
