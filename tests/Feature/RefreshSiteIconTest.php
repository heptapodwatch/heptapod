<?php

declare(strict_types=1);

use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Jobs\RefreshSiteIcon;
use Heptapod\Models\Site;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\Storage;

it('deletes newly uploaded site icon objects when the site is not updated to adopt them', function (string $scenario): void {
    Storage::fake();
    $site = Site::factory()->create(['url' => 'https://icon-cleanup.example.test']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWMICLgDAAJwAX3gXraMAAAAAElFTkSuQmCC', true);
    expect($png)->toBeString();
    $siteId = $site->id;

    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static function (FetchRequest $request) use ($png, $scenario, $site): FetchResult {
        if ($request->url === 'https://icon-cleanup.example.test') {
            return new FetchResult(200, '<html><head><link rel="icon" href="/favicon.png"></head></html>', headers: ['content-type' => ['text/html']]);
        }
        if ($request->url === 'https://icon-cleanup.example.test/favicon.png') {
            match ($scenario) {
                'url_changed' => $site->update(['url' => 'https://changed.example.test']),
                'paused' => $site->update(['paused_at' => now()]),
                'deleted' => $site->delete(),
                'tx_failed' => Site::updating(static function (Site $updating) use ($site): void {
                    if ($updating->id === $site->id && $updating->isDirty('icon_hash')) {
                        throw new RuntimeException('Simulated DB failure during icon adoption');
                    }
                }),
            };

            return new FetchResult(200, $png, headers: ['content-type' => ['image/png']]);
        }

        throw new LogicException('Unexpected request: '.$request->url);
    }));

    $job = new RefreshSiteIcon($siteId, manual: true);
    app()->call($job->handle(...));

    expect(Storage::disk()->allFiles('site-icons/'.$siteId))->toBe([]);
})->with(['url_changed', 'paused', 'deleted', 'tx_failed']);
