<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'telegram_id' => $this->faker->unique()->numberBetween(1000000, 999999999),
            'telegram_username' => $this->faker->userName(),
            'telegram_first_name' => $this->faker->firstName(),
            'telegram_language' => 'zh-hans',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function unverified()
    {
        return $this->state(function (array $attributes) {
            return [
                'email' => null,
            ];
        });
    }
}
