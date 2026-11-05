<?php

declare(strict_types=1);

namespace Database\Factories;

use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Site> */
class SiteFactory extends Factory
{
    #[\Override]
    protected $model = Site::class;

    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'url' => 'https://'.fake()->unique()->domainWord().'.example.test', 'friendly_name' => fake()->company(), 'badge_id' => Str::uuid()->toString(), 'group_name' => ''];
    }
}
