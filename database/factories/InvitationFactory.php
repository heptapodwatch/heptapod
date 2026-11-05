<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Invitation> */
final class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(64)),
            'role' => 'Admin',
            'client_scope' => 'all',
            'client_ids' => [],
            'inviter_id' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }
}
