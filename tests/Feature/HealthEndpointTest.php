<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

it('serves the public health endpoint as non-cacheable plain text without third-party scripts', function (): void {
    $response = $this->get('/up');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('Content-Security-Policy', "default-src 'none'");

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toBe('OK')
        ->and($response->getContent())->not->toContain('<script')
        ->and($response->getContent())->not->toContain('cdn.jsdelivr.net')
        ->and($response->getContent())->not->toContain('fonts.bunny.net');
});

it('serves JSON status when requested and remains reachable during maintenance mode', function (): void {
    Event::fake([DiagnosingHealth::class]);

    $this->artisan('down')->assertSuccessful();
    try {
        $this->getJson('/up')
            ->assertOk()
            ->assertExactJson(['status' => 'up'])
            ->assertHeader('Content-Security-Policy', "default-src 'none'");

        $this->get('/up')->assertOk();
    } finally {
        $this->artisan('up')->assertSuccessful();
    }

    Event::assertDispatchedTimes(DiagnosingHealth::class, 2);
});

it('answers /up with 200 and reports the failure when the heartbeat cannot be written', function (): void {
    Exceptions::fake();
    // A cache whose Redis connection does not exist: every read and write throws.
    config(['cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'unreachable'], 'cache.default' => 'unreachable']);

    $this->get('/up')->assertOk();

    Exceptions::assertReported(fn (InvalidArgumentException $exception): bool => str_contains($exception->getMessage(), '[unreachable]'));
});
