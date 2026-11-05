<?php

declare(strict_types=1);

namespace Database\Seeders;

use Heptapod\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string DEMO_EMAIL = 'test@example.test';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo fixtures are restricted to local and testing environments.');
        }
        $owner = User::query()->where('email', self::DEMO_EMAIL)->first();
        $owner ??= User::factory()->create(['name' => 'Test User', 'email' => self::DEMO_EMAIL]);
        $owner->syncRoles(['Owner']);
    }
}
