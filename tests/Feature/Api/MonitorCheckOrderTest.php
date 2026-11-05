<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02T09:00:00Z'));
});

it('returns checks in ascending id order after an earlier check is updated', function (string $endpoint, string $checksPath): void {
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->withToken($owner->createToken('check-order', ['monitors:read'])->plainTextToken);
    $site = Site::factory()->create(['url' => 'https://check-order.example.test']);
    $first = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Uptime]);
    $second = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::Performance]);
    $third = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::CertificateHealth]);
    // Neither the heap order (the update moves the first row last) nor the unique (site_id, type) index order matches id order.
    $first->update(['interval_seconds' => 120]);
    $url = str_replace(['{id}', '{url}'], [(string) $site->id, urlencode($site->url)], $endpoint);

    $response = $this->getJson($url)->assertOk();

    $response->assertJsonPath($checksPath, [$first->id, $second->id, $third->id]);
})->with([
    'list' => ['/api/monitors', 'data.0.checks.*.id'],
    'detail' => ['/api/monitors/{id}', 'checks.*.id'],
    'by URL' => ['/api/monitors/url/{url}', 'checks.*.id'],
]);
