<?php

declare(strict_types=1);

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Testing\ApiSpec;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->singleton(ApiSpec::class);
    Queue::fake();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('host-change', ['*'])->plainTextToken);
    $registry = app(CheckRegistry::class);
    $this->uptime = Check::factory()->create(['config' => $registry->get(CheckKey::Uptime)->definition()->configSchema->validateConfig([
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'uptime-private']],
    ])]);
    $this->site = $this->uptime->site;
    $this->site->update(['url' => 'https://origin.example/old', 'crawler_headers' => [['name' => 'X-Crawler', 'value' => 'crawler-private']]]);
    $this->health = Check::factory()->create(['site_id' => $this->site->id, 'type' => CheckKey::ApplicationHealth,
        'config' => $registry->get(CheckKey::ApplicationHealth)->definition()->configSchema->validateConfig([
            'result_url' => 'https://origin.example/health', 'secret' => 'health-private',
            'headers' => [['name' => 'X-Health', 'value' => 'health-header-private']],
        ])]);
    expect(app(ClaimService::class)->runNow($this->uptime->id, RunOrigin::System))->not->toBeNull();
});

it('rejects omitted masked or null credentials on an origin change without changing persisted state', function (string $url, mixed $value): void {
    $body = ['url' => $url, 'friendly_name' => 'Must not persist'];
    if ($value !== 'omitted') {
        $body += ['crawler_headers' => [['name' => 'X-Crawler', 'value' => $value]],
            'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => $value]]],
            'application_health_check_settings' => ['secret' => $value, 'headers' => [['name' => 'X-Health', 'value' => $value]]]];
    }
    $site = $this->site->refresh()->getRawOriginal();
    $uptime = $this->uptime->refresh()->getRawOriginal();
    $health = $this->health->refresh()->getRawOriginal();
    $this->putJson('/api/monitors/'.$this->site->id, $body)->assertUnprocessable()->assertJsonValidationErrors([
        'crawler_headers.0.value', 'uptime_check_settings.http_client_headers.0.value',
        'application_health_check_settings.secret', 'application_health_check_settings.headers.0.value',
    ]);
    expect($this->site->refresh()->getRawOriginal())->toBe($site)
        ->and($this->uptime->refresh()->getRawOriginal())->toBe($uptime)
        ->and($this->health->refresh()->getRawOriginal())->toBe($health);
})->with(['https://replacement.example/new', 'http://origin.example/new', 'https://origin.example:8443/new'])->with(['omitted', '********', null]);

it('preserves only the same-position credential after removing an earlier header', function (string $alias): void {
    $this->site->update(['crawler_headers' => [
        ['name' => 'A', 'value' => 'first-private'],
        ['name' => 'B', 'value' => 'second-private'],
    ]]);
    $headers = [['name' => 'A', 'value' => ''], ['name' => 'B', 'value' => '********']];
    $body = $alias === 'crawler_headers' ? ['crawler_headers' => $headers] : ['crawler_settings' => ['headers' => $headers]];

    $this->putJson('/api/monitors/'.$this->site->id, $body)->assertOk();
    expect($this->site->refresh()->crawler_headers)->toBe([['name' => 'B', 'value' => 'second-private']]);
})->with(['crawler_headers', 'crawler_settings.headers']);

it('rejects preserving a deleted header at a new position', function (string $alias): void {
    $this->site->update(['crawler_headers' => [['name' => 'A', 'value' => 'first-private']]]);
    $headers = [['name' => 'X', 'value' => ''], ['name' => 'A', 'value' => '********']];
    $body = $alias === 'crawler_headers' ? ['crawler_headers' => $headers] : ['crawler_settings' => ['headers' => $headers]];

    $this->putJson('/api/monitors/'.$this->site->id, $body)->assertUnprocessable()->assertJsonValidationErrors($alias.'.1.value');
    expect($this->site->refresh()->crawler_headers)->toBe([['name' => 'A', 'value' => 'first-private']]);
})->with(['crawler_headers', 'crawler_settings.headers']);

it('permits explicit re-entry of every credential for a new host', function (): void {
    $this->putJson('/api/monitors/'.$this->site->id, [
        'url' => 'https://replacement.example/new',
        'crawler_settings' => ['headers' => [['name' => 'X-Crawler', 'value' => 'crawler-renewed']]],
        'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => 'uptime-renewed']]],
        'application_health_check_settings' => ['result_url' => 'https://replacement.example/health', 'secret' => 'health-renewed', 'headers' => [['name' => 'X-Health', 'value' => 'health-header-renewed']]],
    ])->assertOk();
    expect($this->site->refresh()->url)->toBe('https://replacement.example/new')
        ->and($this->site->crawler_headers[0]['value'])->toBe('crawler-renewed')
        ->and($this->uptime->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('uptime-renewed')
        ->and($this->health->refresh()->executionConfig()['secret'])->toBe('health-renewed')
        ->and($this->uptime->claim_token)->toBeNull();
});

it('permits explicit clearing of credential lists in the same host-change request', function (): void {
    $this->putJson('/api/monitors/'.$this->site->id, [
        'url' => 'https://replacement.example/new', 'crawler_headers' => [],
        'uptime_check_settings' => ['http_client_headers' => [['name' => 'Authorization', 'value' => '']]],
        'application_health_check_settings' => ['secret' => 'health-renewed', 'headers' => []],
    ])->assertOk();
    expect($this->site->refresh()->crawler_headers)->toBe([])
        ->and($this->uptime->refresh()->executionConfig()['http_client_headers'])->toBe([])
        ->and($this->health->refresh()->executionConfig()['headers'])->toBe([])
        ->and($this->uptime->config_secrets)->toBe([]);
});

it('retains credentials on a path-only edit of the same host', function (): void {
    $this->putJson('/api/monitors/'.$this->site->id, ['url' => 'https://ORIGIN.example:443/new'])->assertOk();
    expect($this->site->refresh()->crawler_headers[0]['value'])->toBe('crawler-private')
        ->and($this->uptime->refresh()->executionConfig()['http_client_headers'][0]['value'])->toBe('uptime-private')
        ->and($this->health->refresh()->executionConfig()['secret'])->toBe('health-private');
});

it('rejects changing an addon target origin alone without changing any stored state', function (string $url, mixed $value): void {
    $body = ['friendly_name' => 'Must not persist', 'application_health_check_settings' => ['result_url' => $url]];
    if ($value !== 'omitted') {
        $body['application_health_check_settings'] += ['secret' => $value, 'headers' => [['name' => 'X-Health', 'value' => $value]]];
    }
    $site = $this->site->refresh()->getRawOriginal();
    $health = $this->health->refresh()->getRawOriginal();
    $uptime = $this->uptime->refresh()->getRawOriginal();
    $this->putJson('/api/monitors/'.$this->site->id, $body)->assertUnprocessable()->assertJsonValidationErrors([
        'application_health_check_settings.secret', 'application_health_check_settings.headers.0.value',
    ]);
    expect($this->site->refresh()->getRawOriginal())->toBe($site)
        ->and($this->health->refresh()->getRawOriginal())->toBe($health)
        ->and($this->uptime->refresh()->getRawOriginal())->toBe($uptime);
})->with(['https://replacement.example/health', 'http://origin.example/health', 'https://origin.example:8443/health'])->with(['omitted', '********', null]);

it('permits explicit credentials or header clearing when only the addon target origin changes', function (bool $clear): void {
    $headers = $clear ? [] : [['name' => 'X-Health', 'value' => 'renewed-header']];
    $this->putJson('/api/monitors/'.$this->site->id, ['application_health_check_settings' => [
        'result_url' => 'https://replacement.example/health', 'secret' => 'renewed-secret', 'headers' => $headers,
    ]])->assertOk();
    expect($this->health->refresh()->executionConfig()['result_url'])->toBe('https://replacement.example/health')
        ->and($this->health->executionConfig()['headers'])->toBe($headers)
        ->and($this->health->executionConfig()['secret'])->toBe('renewed-secret')
        ->and($this->site->refresh()->url)->toBe('https://origin.example/old');
})->with([false, true]);

it('retains credentials on a same-origin addon target edit including the default port', function (): void {
    $this->putJson('/api/monitors/'.$this->site->id, ['application_health_check_settings' => ['result_url' => 'https://ORIGIN.example:443/new-health']])->assertOk();
    expect($this->health->refresh()->executionConfig()['secret'])->toBe('health-private')
        ->and($this->health->executionConfig()['headers'][0]['value'])->toBe('health-header-private');
});

it('rejects userinfo in a public addon target URL without persisting credentials', function (): void {
    $before = $this->health->refresh()->getRawOriginal();
    $this->putJson('/api/monitors/'.$this->site->id, ['application_health_check_settings' => ['result_url' => 'https://user:password@origin.example/health']])->assertUnprocessable()->assertJsonValidationErrors('values.result_url');
    expect($this->health->refresh()->getRawOriginal())->toBe($before);
});

it('does not let uninstalled addon credentials block an otherwise authorized origin change', function (): void {
    $installed = app(CheckRegistry::class);
    $existingTypes = $this->site->checks()->pluck('type')->all();
    $removedType = collect($installed->keys())->first(fn (CheckKey $key): bool => ! in_array($key, $existingTypes, true));
    expect($removedType)->toBeInstanceOf(CheckKey::class);
    $catalog = new CheckRegistry(app());
    foreach ($installed->keys() as $key) {
        if ($key !== $removedType) {
            $catalog->register($installed->get($key)::class, $installed->importMapping($key));
        }
    }
    app()->instance(CheckRegistry::class, $catalog);
    $dormant = Check::factory()->create(['site_id' => $this->site->id, 'type' => $removedType]);
    $dormant->forceFill(['config_secrets' => ['legacy' => 'uninstalled-private']])->save();
    $this->putJson('/api/monitors/'.$this->site->id, [
        'url' => 'https://replacement.example/new', 'crawler_headers' => [],
        'uptime_check_settings' => ['http_client_headers' => []],
        'application_health_check_settings' => ['secret' => 'renewed-secret', 'headers' => []],
    ])->assertOk();
    expect($this->site->refresh()->url)->toBe('https://replacement.example/new')
        ->and($dormant->refresh()->config_secrets)->toBe(['legacy' => 'uninstalled-private']);
});
