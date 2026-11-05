<?php

use Heptapod\Checks\CheckEnablement;
use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Illuminate\Validation\ValidationException;

it('enables the real uptime definition on an HTTP site', function (): void {
    $check = Check::factory()->for(Site::factory()->state(['url' => 'http://store.example']))->create(['enabled' => false]);
    app(CheckEnablement::class)->enable($check, app(CheckRegistry::class)->get(CheckKey::Uptime)->definition());
    expect($check->refresh()->enabled)->toBeTrue();
});

it('rejects HTTPS-required definitions on HTTP sites without changing enablement', function (): void {
    $check = Check::factory()->for(Site::factory()->state(['url' => 'http://store.example']))->create(['enabled' => false]);
    $uptime = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition();
    $https = new CheckDefinition($uptime->key, $uptime->labelKey, $uptime->icon,
        $uptime->queue, $uptime->timeout, $uptime->defaultInterval, $uptime->defaultFailureThreshold,
        $uptime->executionMode, $uptime->configSchema, $uptime->metricSchema, requiresHttps: true);
    try {
        app(CheckEnablement::class)->enable($check, $https);
        $this->fail('HTTP sites must not enable an HTTPS-required check.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['type' => [__('checks.requires_https')]]);
    }
    expect($check->refresh()->enabled)->toBeFalse();
    $check->site->update(['url' => 'https://store.example']);
    app(CheckEnablement::class)->enable($check, $https);
    expect($check->refresh()->enabled)->toBeTrue();
});

it('rejects a mismatched addon definition without enabling the check', function (): void {
    $check = Check::factory()->create(['enabled' => false]);
    expect(fn () => app(CheckEnablement::class)->enable($check, app(CheckRegistry::class)->get(CheckKey::Performance)->definition()))
        ->toThrow(InvalidArgumentException::class);
    expect($check->refresh()->enabled)->toBeFalse();
});
