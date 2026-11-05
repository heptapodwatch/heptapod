<?php

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\SiteCheckSettingsReader;
use Heptapod\Models\Check;
use Heptapod\Models\Site;

it('reads enabled disabled and missing check settings with execution-only secrets', function (): void {
    $site = Site::factory()->create();
    $reader = app(SiteCheckSettingsReader::class);
    expect($reader->read($site->id, CheckKey::Uptime))->toBeNull();
    $config = ['http_client_headers' => [['name' => 'Authorization', 'value' => 'private-token']]];
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime, 'enabled' => true, 'config' => $schema->validateConfig($config)]);
    $settings = $reader->read((string) $site->id, CheckKey::Uptime);
    expect($settings->enabled)->toBeTrue()->and($settings->config)->toBe($config)
        ->and(json_encode($settings))->not->toContain('private-token')
        ->and($settings->__debugInfo())->toBe(['enabled' => true]);
    $check->update(['enabled' => false]);
    expect($reader->read($site->id, CheckKey::Uptime)->enabled)->toBeFalse();
});
