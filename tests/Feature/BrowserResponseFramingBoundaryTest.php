<?php

declare(strict_types=1);

use Heptapod\Testing\BrowserResponseFraming;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

it('does not register browser framing or alter feature response semantics', function (int $status): void {
    expect(app()->bound(BrowserResponseFraming::class))->toBeFalse()
        ->and(Event::getRawListeners()[RequestHandled::class] ?? [])->not->toContain(BrowserResponseFraming::class);
    Route::get('/__feature-framing', fn (): Response => new Response('', $status, [
        'Connection' => 'keep-alive', 'ETag' => '"constant"',
    ]));
    $this->get('/__feature-framing')->assertStatus($status)->assertContent('')
        ->assertHeader('Connection', 'keep-alive')->assertHeader('ETag', '"constant"');
})->with(['204' => 204, '304' => 304]);

it('keeps the normalizer absent from a production application bootstrap and its responses', function (): void {
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Route::get('/__production-framing', fn () => new Symfony\Component\HttpFoundation\Response('', 204, ['Connection' => 'keep-alive']));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle(Illuminate\Http\Request::create('/__production-framing'));
        $listeners = $app['events']->getRawListeners()[Illuminate\Foundation\Http\Events\RequestHandled::class] ?? [];
        echo json_encode([
            'environment' => $app->environment(),
            'bound' => $app->bound(Heptapod\Testing\BrowserResponseFraming::class),
            'registered' => in_array(Heptapod\Testing\BrowserResponseFraming::class, $listeners, true),
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'connection' => $response->headers->get('Connection'),
        ], JSON_THROW_ON_ERROR);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'production']);
    $process->mustRun();
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'environment' => 'production', 'bound' => false, 'registered' => false,
        'status' => 204, 'body' => '', 'connection' => 'keep-alive',
    ]);
});
