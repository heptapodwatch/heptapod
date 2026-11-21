<?php

declare(strict_types=1);

use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

it('keeps custom header values encrypted, masked, and out of provider failure diagnostics', function (): void {
    $this->actingAs(User::factory()->owner()->create());
    $url = 'https://receiver.example.test/hook';
    $value = 'Bearer example-header-secret';
    $id = $this->postJson(route('notification-channels.store'), [
        'channel' => 'webhook', 'label' => 'Custom receiver', 'recipient_kind' => 'team', 'locale' => 'en',
        'config' => ['url' => $url, 'secret' => 'example-signing-secret-of-32-chars', 'headers' => [
            ['name' => 'X-Old', 'value' => 'example-old-secret'], ['name' => 'authorization', 'value' => $value],
        ]],
    ])->assertCreated()->json('id');
    $row = DB::table('notification_destinations')->find($id);
    expect($row->config)->not->toContain($value)->and($row->config_secrets)->not->toContain($value);
    $page = $this->getJson(route('settings.notifications.team.data'))->assertOk()->assertDontSee($value);
    $config = collect($page->json('channels'))->firstWhere('id', $id)['config'];
    expect($config['headers'][1])->toBe(['name' => 'authorization', 'value' => null]);

    $headers = [['name' => 'authorization', 'value' => '********']];
    $this->patchJson(route('notification-channels.update', $id), [
        'config' => ['url' => 'https://other.example.test/hook', 'secret' => 'different-signing-secret-of-32-chars', 'headers' => $headers],
    ])->assertUnprocessable()->assertJsonValidationErrors('config.headers.0.value');
    $headers[0]['name'] = 'Authorization';
    $this->patchJson(route('notification-channels.update', $id), ['config' => ['headers' => $headers]])->assertOk();
    expect(NotificationDestination::query()->findOrFail($id)->destinationConfig()->values()['headers'])->toBe([['name' => 'Authorization', 'value' => $value]]);

    Http::preventStrayRequests();
    Http::fake([$url => Http::response(['error' => $value], 403)]);
    $logs = new TestHandler;
    $logger = Log::getLogger();
    $logger->pushHandler($logs);
    try {
        $this->postJson(route('notification-channels.test', $id))->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('diagnosis', 'HTTP 403 — RequestException')->assertDontSee($value);
        expect(json_encode($logs->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($value);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === [$value]
            && ! $request->hasHeader('X-Old') && $request->hasHeader('Signature') && $request->hasHeader('Content-Type', 'application/json'));
    } finally {
        $logger->popHandler();
    }
    $this->patchJson(route('notification-channels.update', $id), ['config' => ['headers' => []]])->assertOk();
    expect(NotificationDestination::query()->findOrFail($id)->destinationConfig()->values()['headers'])->toBe([]);
});

it('rejects unsafe or ambiguous custom header settings at save', function (array $headers, string $field): void {
    $this->actingAs(User::factory()->owner()->create())->postJson(route('notification-channels.store'), [
        'channel' => 'webhook', 'label' => 'Custom receiver', 'recipient_kind' => 'team', 'locale' => 'en',
        'config' => ['url' => 'https://receiver.example.test/hook', 'secret' => 'example-signing-secret-of-32-chars', 'headers' => $headers],
    ])->assertUnprocessable()->assertJsonValidationErrors('values.headers'.$field);
})->with([
    'six headers' => [array_map(fn (int $index): array => ['name' => 'X-Field-'.$index, 'value' => 'example-value'], range(1, 6)), ''],
    'signature' => [[['name' => 'sIgNaTuRe', 'value' => 'example-value']], '.0.name'],
    'content type' => [[['name' => 'content-type', 'value' => 'text/plain']], '.0.name'],
    'host' => [[['name' => 'HOST', 'value' => 'receiver.example.test']], '.0.name'],
    'invalid name' => [[['name' => 'X Header', 'value' => 'example-value']], '.0.name'],
    'invalid value' => [[['name' => 'X-Header', 'value' => "value\r\nline"]], '.0.value'],
    'duplicate names' => [[['name' => 'Authorization', 'value' => 'example-one'], ['name' => 'authorization', 'value' => 'example-two']], '.1.name'],
]);
