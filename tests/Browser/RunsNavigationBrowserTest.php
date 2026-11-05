<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Vite;

it('opens every run from Activity on mobile when the translated link wraps', function (): void {
    Vite::useHotFile(storage_path('framework/testing.hot'));
    $this->seed(DatabaseSeeder::class);
    $owner = User::query()->where('email', DatabaseSeeder::DEMO_EMAIL)->sole();
    $owner->update(['locale' => 'de']);
    $this->actingAs($owner);

    visit('/activity')->resize(390, 844)
        ->click('main a[href="/runs"]')->assertPathIs('/runs')
        ->assertSee('Versuchsverlauf')->assertNoJavaScriptErrors();
});
