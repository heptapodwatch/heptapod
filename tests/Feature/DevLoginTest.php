<?php

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReviewStatesSeeder;
use Heptapod\Models\User;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Spatie\LoginLink\Exceptions\NotAllowedInCurrentEnvironment;
use Spatie\LoginLink\Exceptions\NotAllowedInCurrentHost;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

test('the seeded demo Owner is the only demo account and the real dev-login form signs it in', function (): void {
    app()->instance('env', 'local');
    app()->register(RouteServiceProvider::class, force: true);
    $this->seed();
    $owner = User::query()->sole();
    $page = $this->get('http://heptapod-app/dev-login')->assertOk();
    expect(preg_match('/<input[^>]*name="email"[^>]*value="([^"]*)"/', $page->getContent(), $email))->toBe(1);
    expect(preg_match('/<input[^>]*name="_token"[^>]*value="([^"]*)"/', $page->getContent(), $token))->toBe(1);
    expect($email[1])->toBe($owner->email);
    $this->post('http://heptapod-app/laravel-login-link-login', [
        'email' => $email[1], '_token' => $token[1],
    ])->assertRedirect('http://heptapod-app/sites');
    $this->assertAuthenticatedAs($owner);
    expect($owner->email)->toBe('test@example.test')
        ->and($owner->hasRole('Owner'))->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeTrue();
    $this->seed();
    $this->seed([DemoSeeder::class, ReviewStatesSeeder::class]);
    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->id)->toBe($owner->id);
});

test('production seeding refuses to create a known-password Owner', function (?string $seeder): void {
    app()->instance('env', 'production');
    $arguments = ['--force' => true, '--no-interaction' => true];
    if ($seeder !== null) {
        $arguments['--class'] = $seeder;
    }
    expect(fn () => $this->artisan('db:seed', $arguments)->run())
        ->toThrow(LogicException::class, 'Demo fixtures are restricted to local and testing environments.');
    expect(User::query()->count())->toBe(0);
})->with(['default' => [null], 'demo' => [DemoSeeder::class]]);

test('local login page offers the demo sign-in that returns to the deep link', function (): void {
    app()->instance('env', 'local');
    app()->register(RouteServiceProvider::class, force: true);
    $user = User::factory()->create();
    $this->get('http://heptapod-app/sites/32/settings')->assertRedirect('http://heptapod-app/login');
    $devLogin = $this->get('http://heptapod-app/login')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('auth/login')
        ->where('devLogin', ['action' => '/laravel-login-link-login', 'email' => 'test@example.test', 'redirectUrl' => 'http://heptapod-app/sites/32/settings']))
        ->inertiaProps('devLogin');
    expect(preg_match('/<input[^>]*name="redirect_url"[^>]*value="([^"]*)"/', $this->get('http://heptapod-app/dev-login')->getContent(), $devLoginPageRedirect))->toBe(1)
        ->and($devLoginPageRedirect[1])->toBe('http://heptapod-app/sites/32/settings');

    $this->withSession(['_token' => 'test-token'])
        ->post('http://heptapod-app'.$devLogin['action'], ['email' => $user->email, 'redirect_url' => $devLogin['redirectUrl'], '_token' => 'test-token'])
        ->assertRedirect('http://heptapod-app/sites/32/settings');
    $this->assertAuthenticatedAs($user);
});

test('login page has no demo sign-in outside local or on an unlisted host', function (string $environment, string $origin): void {
    app()->instance('env', $environment);
    $this->get($origin.'/login')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('auth/login')
        ->missing('devLogin'));
})->with([
    'testing environment, listed host' => ['testing', 'http://heptapod-app'],
    'production environment, listed host' => ['production', 'http://heptapod-app'],
    'local environment, unlisted host' => ['local', 'http://untrusted.example'],
]);

test('demo sign-in ignores an intended URL outside this origin', function (string $intended): void {
    app()->instance('env', 'local');
    $this->withSession(['url.intended' => $intended])->get('http://heptapod-app/login')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('devLogin.redirectUrl', null));
})->with([
    'foreign host' => ['https://evil.example/sites'],
    'protocol-relative' => ['//evil.example/sites'],
    'backslash' => ['/\\evil.example/sites'],
    'host prefix' => ['http://heptapod-app.evil.example/sites'],
    'userinfo' => ['http://heptapod-app@evil.example/sites'],
    'other scheme' => ['https://heptapod-app/sites'],
]);

test('dev login page is not registered outside local', function (): void {
    $this->get('/dev-login')->assertNotFound();
});

test('login link rejects non local environment', function (): void {
    $this->withoutExceptionHandling();
    $this->expectException(NotAllowedInCurrentEnvironment::class);

    $this->post('/laravel-login-link-login');
});

test('local login link rejects unlisted hosts', function (): void {
    app()->instance('env', 'local');
    $this->withoutExceptionHandling();
    $this->expectException(NotAllowedInCurrentHost::class);

    $this->withSession(['_token' => 'test-token'])
        ->post('http://untrusted.example/laravel-login-link-login', ['_token' => 'test-token']);
});

test('configured exact review hosts work only in local and reject wildcards', function (): void {
    $environment = $_ENV;
    $server = $_SERVER;
    $_ENV['LOGIN_LINK_ALLOWED_HOSTS'] = $_SERVER['LOGIN_LINK_ALLOWED_HOSTS'] = ' review-stack.example.test ';
    try {
        config(['login-link' => require config_path('login-link.php')]);
        expect(config('login-link.allowed_hosts'))->toContain('localhost', 'heptapod-app', 'review-stack.example.test');
        app()->instance('env', 'local');
        $user = User::factory()->create();
        $this->withSession(['_token' => 'test-token'])->post('http://review-stack.example.test/laravel-login-link-login', ['email' => $user->email, '_token' => 'test-token'])
            ->assertRedirect('http://review-stack.example.test/sites');
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        app()->instance('env', 'production');
        $this->withoutExceptionHandling();
        expect(fn () => $this->withSession(['_token' => 'test-token'])->post('http://review-stack.example.test/laravel-login-link-login', ['email' => $user->email, '_token' => 'test-token']))
            ->toThrow(NotAllowedInCurrentEnvironment::class);
        $this->assertGuest();
        $_ENV['LOGIN_LINK_ALLOWED_HOSTS'] = $_SERVER['LOGIN_LINK_ALLOWED_HOSTS'] = '*.example.test';
        expect(fn () => require config_path('login-link.php'))->toThrow(InvalidArgumentException::class);
    } finally {
        $_ENV = $environment;
        $_SERVER = $server;
    }
});

test('local login link logs in on compose alias', function (): void {
    app()->instance('env', 'local');
    $user = User::factory()->create();

    $this->withSession(['_token' => 'test-token'])
        ->post('http://heptapod-app/laravel-login-link-login', [
            'email' => $user->email,
            '_token' => 'test-token',
        ])
        ->assertRedirect('http://heptapod-app/sites');

    $this->assertAuthenticatedAs($user);
});
