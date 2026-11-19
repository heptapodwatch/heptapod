<?php

declare(strict_types=1);

use Heptapod\Http\Middleware\HandleInertiaRequests;
use Heptapod\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Symfony\Component\HttpFoundation\Response;

// The production web role serves requests from long-lived Octane workers (docker/prod/Caddyfile).
it('serves each worker request with only its own user, locale, time zone and flash', function (): void {
    $german = User::factory()->owner()->create(['locale' => 'de', 'timezone' => 'Asia/Tokyo']);
    $english = User::factory()->member()->create(['locale' => 'en', 'timezone' => 'UTC']);
    $client = new FakeClient([]);
    // The worker boots on this test's application, so it shares the test's database transaction.
    $worker = new FakeWorker(new class($this->app) extends ApplicationFactory
    {
        public function __construct(private readonly Application $app)
        {
            parent::__construct($app->basePath());
        }

        public function createApplication(array $initialInstances = []): Application
        {
            foreach ($initialInstances as $key => $instance) {
                $this->app->instance($key, $instance);
            }

            return $this->warm($this->app);
        }
    }, $client);
    $worker->boot();
    $version = $this->app->make(HandleInertiaRequests::class)->version(Request::create('/'));
    $send = function (string $method, string $uri, array $cookies = [], array $data = []) use ($worker, $client, $version): Response {
        $request = Request::create($uri, $method, $data, $cookies, server: [
            'HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => $version, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $worker->handle($request, new RequestContext(['request' => $request]));
        expect($client->errors)->toBe([]);

        return array_pop($client->responses);
    };
    $signIn = function (User $user) use ($send): array {
        $response = $send('POST', '/login', data: ['email' => $user->email, 'password' => 'password']);
        expect($response->getStatusCode())->toBe(302);

        return collect($response->headers->getCookies())->mapWithKeys(fn ($cookie): array => [$cookie->getName() => $cookie->getValue()])->all();
    };
    $page = fn (Response $response): array => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    $germanSession = $signIn($german);
    expect($send('PATCH', '/settings/profile', $germanSession, ['name' => $german->name, 'email' => $german->email, 'locale' => 'de'])->headers->get('Location'))
        ->toEndWith('/settings/profile');

    $guest = $page($send('GET', '/login'));
    expect($guest['props']['auth']['user'])->toBeNull()
        ->and($guest['props']['locale'])->toBe('en')
        ->and($guest['props']['timezone'])->toBe('UTC')
        ->and($guest['flash'] ?? [])->toBe([]);

    $other = $page($send('GET', '/settings/profile', $signIn($english)));
    expect($other['props']['auth']['user']['id'])->toBe($english->id)
        ->and($other['props']['locale'])->toBe('en')
        ->and($other['props']['timezone'])->toBe('UTC')
        ->and($other['flash'] ?? [])->toBe([]);

    $own = $page($send('GET', '/settings/profile', $germanSession));
    expect($own['props']['auth']['user']['id'])->toBe($german->id)
        ->and($own['props']['locale'])->toBe('de')
        ->and($own['props']['timezone'])->toBe('Asia/Tokyo')
        ->and($own['flash']['toast']['message'])->toBe('Profil aktualisiert.');
});
