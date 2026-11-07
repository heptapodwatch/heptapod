<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
});

it('backfills in ID order without rewriting URLs or losing collisions and remains compatible with old writes', function (): void {
    $migration = require database_path('migrations/2026_11_07_195743_add_url_key_to_sites.php');
    $migration->down();
    $first = Site::factory()->create(['url' => 'https://EXAMPLE.com/']);
    $second = Site::factory()->create(['url' => 'http://example.com']);
    $invalid = Site::factory()->create(['url' => 'ftp://legacy.example']);
    $before = DB::table('sites')->select('id', 'url', 'client_id', 'updated_at')->orderBy('id')->get()->all();

    $migration->up();

    expect(DB::table('sites')->select('id', 'url', 'client_id', 'updated_at')->orderBy('id')->get()->all())->toEqual($before);
    expect(DB::table('sites')->where('id', $first->id)->value('url_key'))->toBe(hash('sha256', 'example.com'));
    expect(DB::table('sites')->where('id', $second->id)->value('url_key'))->toBeNull();
    expect(DB::table('sites')->where('id', $invalid->id)->value('url_key'))->toBeNull();
    $index = collect(Schema::getIndexes('sites'))->firstWhere('name', 'sites_url_key_unique');
    expect($index['unique'])->toBeTrue();

    // The previous release selects sites.* and writes only supplied columns; the nullable key is ignored.
    expect(Site::query()->findOrFail($second->id)->url)->toBe('http://example.com');
    $second->update(['friendly_name' => 'Old release edit']);
    $oldWrite = Site::factory()->create(['url' => 'https://old-release.example']);
    expect($second->refresh()->friendly_name)->toBe('Old release edit');
    expect($oldWrite->getAttribute('url_key'))->toBeNull();
    $this->artisan('heptapod:sites:duplicates')->expectsOutputToContain('https://EXAMPLE.com/')->expectsOutputToContain('http://example.com')->assertSuccessful();
});

it('backfills ten thousand sites in chunks and preserves every stored address', function (): void {
    $migration = require database_path('migrations/2026_11_07_195743_add_url_key_to_sites.php');
    $migration->down();
    $client = Client::factory()->create();
    foreach (array_chunk(range(1, 10000), 500) as $ids) {
        DB::table('sites')->insert(array_map(static fn (int $id): array => ['client_id' => $client->id, 'badge_id' => sprintf('00000000-0000-4000-8000-%012d', $id), 'url' => 'https://volume-'.$id.'.example/'], $ids));
    }
    $started = hrtime(true);

    $migration->up();

    $milliseconds = (hrtime(true) - $started) / 1e6;
    fwrite(STDOUT, sprintf("\nurl_key backfill: 10000 sites in %.2f ms\n", $milliseconds));
    expect(DB::table('sites')->whereNotNull('url_key')->count())->toBe(10000);
    expect(DB::table('sites')->where('url', 'like', '%/')->count())->toBe(10000);
    expect(DB::table('sites')->where('url', 'https://volume-10000.example/')->value('url_key'))->toBe(hash('sha256', 'volume-10000.example'));
});
