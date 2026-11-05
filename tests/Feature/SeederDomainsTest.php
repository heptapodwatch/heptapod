<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('keeps seeded hosts reserved', function (string $seeder): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    Queue::fake([RefreshSiteIcon::class]);
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $this->seed('Database\\Seeders\\'.$seeder);
    $hosts = Site::query()->pluck('url')->map(fn (string $url): mixed => parse_url($url, PHP_URL_HOST))
        ->concat(User::query()->pluck('email')->map(fn (string $email): string => substr($email, strrpos($email, '@') + 1)));
    expect($hosts)->not->toBeEmpty();
    $nonReserved = [];
    foreach ($hosts as $host) {
        expect($host)->toBeString();
        if (! str_ends_with($host, '.example') && ! str_ends_with($host, '.test')) {
            $nonReserved[] = $host;
        }
    }
    expect(array_values(array_unique($nonReserved)))->toBe([]);
})->with(array_map(static fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME)], glob(__DIR__.'/../../database/seeders/*.php')));
