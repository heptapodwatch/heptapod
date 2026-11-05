<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\UpdateCheckConfig;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\User;
use Heptapod\Testing\FakeOutboundHttp;

it('saves check settings through the scoped site check route and preserves omitted secrets', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'config' => $schema->validateConfig([
        'http_client_headers' => [['name' => 'Authorization', 'value' => 'private-token-1234']],
    ])]);
    $this->actingAs($user)->patch('/sites/'.$check->site_id.'/checks/'.$check->id, [
        'timeout' => 12,
    ])->assertRedirect();

    expect($check->refresh()->executionConfig()['timeout'])->toBe(12)
        ->and($check->executionConfig()['http_client_headers'][0]['value'])->toBe('private-token-1234');
    expect($this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->getContent())->not->toContain('private-token-1234');
});

it('inherits uptime alert timing when the settings form submits a blank threshold', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $site = $check->site;
    $site->update(['alert_after_minutes' => 5, 'alert_recovery_checks' => 3]);
    $url = '/sites/'.$site->id.'/checks/'.$check->id;

    $this->actingAs($owner)->from($url)->patch($url, ['failed_notification_threshold' => ''])
        ->assertRedirect($url)->assertSessionHasNoErrors();

    expect($site->refresh()->alert_after_minutes)->toBeNull()
        ->and($site->alert_recovery_checks)->toBeNull();
});

it('does not allow members or checks from another site to be edited', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime]);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->patch('/sites/'.$check->site_id.'/checks/'.$check->id, ['timeout' => 12])->assertForbidden();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $other = Check::factory()->create();
    $this->actingAs($owner)->patch('/sites/'.$check->site_id.'/checks/'.$other->id, ['timeout' => 12])->assertNotFound();
    expect($check->refresh()->executionConfig())->not->toHaveKey('timeout');
});

it('probes unsaved health settings through the public-only seam and gates the save', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/health', 'secret' => 'stored-secret',
    ])]);
    $fake = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $fake);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $probe = $url.'/health/probe';
    $values = ['result_url' => 'https://health.example.test/new', 'secret' => 'new-secret'];
    $this->actingAs($owner);
    $fake->respondUsing(function (FetchRequest $request): FetchResult {
        expect($request->publicTargetsOnly)->toBeTrue()
            ->and($request->maxRedirects)->toBe(0)
            ->and($request->headers['oh-dear-health-check-secret'])->toBe(['new-secret']);

        return new FetchResult(401, body: 'private-health-token', effectiveUrl: $request->url);
    });
    $this->postJson($probe, $values)->assertOk()->assertExactJson(['result' => 'unauthorized', 'component_count' => null])
        ->assertDontSee('private-health-token');
    $this->from($url)->patch($url, $values)->assertSessionHasErrors('result_url');
    expect($check->refresh()->executionConfig()['result_url'])->toBe('https://health.example.test/health');

    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200, body: '{invalid}', effectiveUrl: $request->url));
    $this->postJson($probe, $values)->assertOk()->assertExactJson(['result' => 'invalid_format', 'component_count' => null]);
    $fake->respondUsing(static fn (FetchRequest $request): FetchResult => new FetchResult(200,
        body: json_encode(['finishedAt' => time(), 'checkResults' => [['name' => 'Database', 'status' => 'ok']]], JSON_THROW_ON_ERROR), effectiveUrl: $request->url));
    $this->postJson($probe, $values)->assertOk()->assertExactJson(['result' => 'ok', 'component_count' => 1]);
    $this->from($url)->patch($url, $values)->assertRedirect($url);
    expect($check->refresh()->executionConfig()['result_url'])->toBe($values['result_url'])
        ->and($check->executionConfig()['secret'])->toBe('new-secret')
        ->and($this->get($url)->getContent())->not->toContain('new-secret');
    $this->from($url)->patch($url, ['result_url' => 'https://health.example.test/again', 'secret' => ''])->assertRedirect($url);
    expect($check->refresh()->executionConfig()['secret'])->toBe('new-secret');
});

it('blocks cross-origin health probes before forwarding stored credentials', function (array $changes, string $errorPath, string $targetUrl): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/health', 'secret' => 'stored-secret',
        'headers' => [['name' => 'X-Auth', 'value' => 'stored-header-secret']],
    ])]);
    $fake = new FakeOutboundHttp;
    app()->instance(OutboundHttp::class, $fake);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;
    $values = ['result_url' => $targetUrl, ...$changes];

    $this->actingAs($owner)->postJson($url.'/health/probe', $values)
        ->assertUnprocessable()->assertJsonValidationErrors($errorPath);
    $this->patchJson($url, $values)->assertUnprocessable()->assertJsonValidationErrors($errorPath);
    expect($fake->requests)->toBe([])
        ->and($check->refresh()->executionConfig()['result_url'])->toBe('https://health.example.test/health');
})->with([
    'omitted secret and headers' => [[], 'application_health_check_settings.secret', 'https://new-host.example.test/health'],
    'blank secret and omitted headers' => [['secret' => ''], 'application_health_check_settings.secret', 'https://new-host.example.test/health'],
    'null secret and omitted headers' => [['secret' => null], 'application_health_check_settings.secret', 'https://new-host.example.test/health'],
    'masked secret and headers' => [['secret' => '********', 'headers' => [['name' => 'X-Auth', 'value' => '********']]], 'application_health_check_settings.secret', 'https://new-host.example.test/health'],
    'reentered secret but inherited headers' => [['secret' => 'replacement-secret'], 'application_health_check_settings.headers.0.value', 'https://new-host.example.test/health'],
    'reentered secret but blank header' => [['secret' => 'replacement-secret', 'headers' => [['name' => 'X-Auth', 'value' => '']]], 'application_health_check_settings.headers.0.value', 'https://new-host.example.test/health'],
    'scheme changed' => [[], 'application_health_check_settings.secret', 'http://health.example.test/health'],
    'port changed' => [[], 'application_health_check_settings.secret', 'https://health.example.test:8443/health'],
]);

it('probes a new health origin only with newly supplied credentials', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/old', 'secret' => 'stored-secret',
        'headers' => [['name' => 'X-Auth', 'value' => 'stored-header-secret']],
    ])]);
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(function (FetchRequest $request): FetchResult {
        expect($request->headers)->toBe(['oh-dear-health-check-secret' => ['fresh-secret'], 'x-auth' => ['fresh-header']]);

        return new FetchResult(200, body: json_encode(['finishedAt' => time(), 'checkResults' => []], JSON_THROW_ON_ERROR), effectiveUrl: $request->url);
    });
    app()->instance(OutboundHttp::class, $fake);

    $this->actingAs($owner)->postJson('/sites/'.$check->site_id.'/checks/'.$check->id.'/health/probe', [
        'result_url' => 'https://new-host.example.test/health', 'secret' => 'fresh-secret',
        'headers' => [['name' => 'X-Auth', 'value' => 'fresh-header']],
    ])->assertOk()->assertExactJson(['result' => 'ok', 'component_count' => 0]);
    expect($fake->requests)->toHaveCount(1);
});

it('refuses to save a health configuration changed while its probe was in flight', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/old', 'secret' => 'stored-secret-S1',
    ])]);
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(function (FetchRequest $request) use ($check): FetchResult {
        expect($request->headers['oh-dear-health-check-secret'])->toBe(['stored-secret-S1']);
        app(UpdateCheckConfig::class)($check->refresh(), ['secret' => 'concurrent-secret-S2']);

        return new FetchResult(200, body: json_encode(['finishedAt' => time(), 'checkResults' => []], JSON_THROW_ON_ERROR), effectiveUrl: $request->url);
    });
    app()->instance(OutboundHttp::class, $fake);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id;

    $this->actingAs($owner)->patchJson($url, ['result_url' => 'https://health.example.test/new', 'secret' => ''])
        ->assertStatus(409)->assertJsonPath('message', __('checks.settings_changed'));
    expect($fake->requests)->toHaveCount(1)
        ->and($check->refresh()->executionConfig()['result_url'])->toBe('https://health.example.test/old')
        ->and($check->executionConfig()['secret'])->toBe('concurrent-secret-S2');
});

it('restricts health probes and keeps the stored secret server-side', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $restricted = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $restricted->assignRole('Member');
    $restricted->clients()->attach(Client::factory()->create());
    $schema = app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema;
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth, 'config' => $schema->validateConfig([
        'result_url' => 'https://health.example.test/health', 'secret' => 'stored-secret',
    ])]);
    $url = '/sites/'.$check->site_id.'/checks/'.$check->id.'/health/probe';
    $fake = new FakeOutboundHttp;
    $fake->respondUsing(function (FetchRequest $request): FetchResult {
        expect($request->headers['oh-dear-health-check-secret'])->toBe(['new-secret']);

        return new FetchResult(200, failure: new FetchFailure(FetchFailureKind::PrivateTarget, 200));
    });
    app()->instance(OutboundHttp::class, $fake);
    $this->actingAs($member)->postJson($url, ['result_url' => 'http://127.0.0.1/'])->assertForbidden();
    $this->actingAs($restricted)->postJson($url, ['result_url' => 'http://127.0.0.1/'])->assertNotFound();
    $other = Check::factory()->create(['type' => CheckKey::ApplicationHealth]);
    $this->actingAs($owner)->postJson('/sites/'.$check->site_id.'/checks/'.$other->id.'/health/probe', ['result_url' => 'http://127.0.0.1/'])->assertNotFound();
    expect($fake->requests)->toBe([]);
    $this->actingAs($admin)->postJson($url, ['result_url' => 'https://health.example.test/health', 'secret' => 'new-secret'])
        ->assertOk()->assertExactJson(['result' => 'unreachable', 'component_count' => null]);
    $this->actingAs($owner)->postJson($url, ['result_url' => 'http://127.0.0.1/', 'secret' => 'new-secret'])
        ->assertOk()->assertExactJson(['result' => 'unreachable', 'component_count' => null]);
    expect($this->get('/sites/'.$check->site_id.'/checks/'.$check->id)->getContent())->not->toContain('stored-secret');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson($url, ['result_url' => 'https://health.example.test/health', 'secret' => 'new-secret'])->assertOk();
    }
    $this->postJson($url, ['result_url' => 'https://health.example.test/health', 'secret' => 'new-secret'])->assertStatus(429);
    $this->patch('/sites/'.$check->site_id.'/checks/'.$check->id, ['result_url' => 'https://health.example.test/new'])
        ->assertStatus(429);
});
