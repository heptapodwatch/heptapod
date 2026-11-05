<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    #[\Override]
    protected $model = Client::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'locale' => 'en', 'timezone' => 'UTC', 'is_internal' => false];
    }
}
