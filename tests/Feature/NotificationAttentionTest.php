<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Monolog\Handler\TestHandler;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

it('quarantines legacy short secrets without sending and reactivates only after valid configuration is saved', function (string $secret): void {
    $channel = app(ChannelRegistry::class)->get('webhook');
    $values = ['url' => 'https://attention.example/receiver', 'secret' => 'valid-secret'];
    $destination = NotificationDestination::factory()->create(['channel' => 'webhook', 'config' => $channel->configSchema()->validateConfig($values)]);
    notificationRule($destination);
    $incident = Incident::factory()->create();
    $event = Event::factory()->create(['site_id' => $incident->site_id, 'check_id' => $incident->check_id, 'incident_id' => $incident->id, 'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed]);
    app(PlanNotificationEvent::class)($event->id);
    $delivery = Delivery::query()->where('destination_id', $destination->id)->firstOrFail();
    $destination->forceFill(['config_secrets' => ['url' => $values['url'], 'secret' => $secret]])->save();
    $healthy = NotificationDestination::factory()->create(['channel' => 'webhook', 'config' => $channel->configSchema()->validateConfig($values)]);
    $migration = require database_path('migrations/2026_10_02_122934_flag_notification_destinations_with_short_secrets.php');
    $migration->up();
    expect($destination->refresh()->getAttribute('needs_attention'))->toBeTrue()
        ->and($destination->enabled)->toBeFalse()
        ->and($destination->getAttribute('attention_reason'))->toBe('notifications.secret_too_short')
        ->and($destination->config_secrets['secret'])->toBe($secret)
        ->and($healthy->refresh()->enabled)->toBeTrue()
        ->and($healthy->getAttribute('needs_attention'))->toBeFalse();
    $ciphertext = $destination->getRawOriginal('config_secrets');
    $migration->up();
    expect($destination->refresh()->getAttribute('needs_attention'))->toBeTrue()
        ->and($destination->enabled)->toBeFalse()
        ->and($destination->getAttribute('attention_reason'))->toBe('notifications.secret_too_short')
        ->and($destination->getRawOriginal('config_secrets'))->toBe($ciphertext);
    $migration->down();
    expect($destination->refresh()->enabled)->toBeFalse()
        ->and($destination->getRawOriginal('config_secrets'))->toBe($ciphertext);
    $migration->up();
    expect($destination->refresh()->getAttribute('needs_attention'))->toBeTrue()
        ->and($destination->enabled)->toBeFalse()
        ->and($destination->getAttribute('attention_reason'))->toBe('notifications.secret_too_short')
        ->and($destination->getRawOriginal('config_secrets'))->toBe($ciphertext)
        ->and($healthy->refresh()->enabled)->toBeTrue();
    Http::preventStrayRequests();
    Http::fake(['https://attention.example/receiver' => Http::response()]);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $this->actingAs($owner)->postJson(route('notification-channels.test', $destination))->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'notifications.secret_too_short')->assertJsonPath('diagnosis', 'Secret shorter than 4 characters; please renew it.');
    app(SendNotificationDelivery::class)($delivery->id);
    expect($delivery->refresh()->suppressed)->toBeTrue()->and($delivery->suppression_reason)->toBe('destination_disabled');
    Http::assertNothingSent();
    $this->get(route('sites.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->assertOk()->assertJsonCount(1, 'props.notificationAttention')->assertJsonPath('props.notificationAttention.0.id', $destination->id)->assertJsonPath('props.notificationAttention.0.reason', 'Secret shorter than 4 characters; please renew it.')->assertJsonMissingPath('props.notificationAttention.0.config_secrets');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->get(route('sites.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->assertOk()->assertJsonCount(1, 'props.notificationAttention');
    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get(route('sites.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->assertOk()->assertJsonCount(0, 'props.notificationAttention');
    $handler = new TestHandler;
    $logger = Log::getLogger();
    $logger->pushHandler($handler);
    try {
        $this->artisan('heptapod:notifications:attention')->assertSuccessful();
        expect($handler->hasWarningRecords())->toBeTrue();
        expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain('attention.example', 'config_secrets');
    } finally {
        $logger->popHandler();
    }
    $destination->update(['enabled' => true, 'needs_attention' => false, 'attention_reason' => null]);
    expect($destination->refresh()->enabled)->toBeFalse();
    $destination->update(['config' => $channel->configSchema()->validateConfig(['url' => $values['url'], 'secret' => ' renewed-secret '])]);
    expect($destination->refresh()->getAttribute('needs_attention'))->toBeFalse()
        ->and($destination->getAttribute('attention_reason'))->toBeNull()
        ->and($destination->enabled)->toBeTrue()
        ->and($destination->destinationConfig()->string('secret'))->toBe(' renewed-secret ');
    $this->actingAs($owner)->postJson(route('notification-channels.test', $destination))->assertOk()->assertJsonPath('status', 'sent');
    Http::assertSentCount(1);
})->with(['a', 'ab', 'abc', '   ', '  ab  ']);

it('handles a legacy execution configuration without throwing or sending even before quarantine', function (string $locale, string $hint): void {
    $channel = app(ChannelRegistry::class)->get('webhook');
    $destination = NotificationDestination::factory()->create(['channel' => 'webhook', 'locale' => $locale, 'config' => $channel->configSchema()->validateConfig(['url' => 'https://attention.example/receiver', 'secret' => 'valid-secret'])]);
    $destination->forceFill(['config_secrets' => ['url' => 'https://attention.example/receiver', 'secret' => 'abc']])->save();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    Http::preventStrayRequests();
    Http::fake();
    $this->actingAs($owner)->postJson(route('notification-channels.test', $destination))->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'notifications.secret_too_short')->assertJsonPath('diagnosis', $hint);
    Http::assertNothingSent();
})->with([
    ['en', 'Secret shorter than 4 characters; please renew it.'],
    ['de', 'Das Geheimnis ist kürzer als 4 Zeichen. Bitte erneuern.'],
]);
