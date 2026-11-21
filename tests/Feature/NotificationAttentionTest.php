<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Schema\ValidatedConfig;
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

it('pauses saved plain-HTTP ntfy and Teams channels until an HTTPS address or a listed private host is saved', function (): void {
    config(['heptapod.outbound.delivery_private_hosts' => ['ntfy.lan']]);
    $ntfy = fn (string $server): NotificationDestination => NotificationDestination::factory()->create(['channel' => 'ntfy', 'config' => new ValidatedConfig(['server' => $server, 'topic' => 'alerts'], [])]);
    $publicHttp = $ntfy('http://ntfy.example');
    $listedHttp = $ntfy('http://ntfy.lan:8080');
    $secure = $ntfy('https://ntfy.example');
    $teams = NotificationDestination::factory()->create(['channel' => 'microsoftTeams', 'config' => new ValidatedConfig([], ['url' => 'http://teams.example/workflow'])]);
    $pausedByOperator = $ntfy('http://ntfy.example');
    $pausedByOperator->update(['enabled' => false]);

    (require database_path('migrations/2026_11_21_130000_flag_plain_http_notification_destinations.php'))->up();

    foreach ([$publicHttp, $teams] as $flagged) {
        expect($flagged->refresh()->enabled)->toBeFalse()->and($flagged->getAttribute('needs_attention'))->toBeTrue()
            ->and($flagged->getAttribute('attention_reason'))->toBe('notifications.needs_https');
    }
    expect($publicHttp->destinationConfig()->string('server'))->toBe('http://ntfy.example');
    foreach ([$listedHttp, $secure] as $kept) {
        expect($kept->refresh()->enabled)->toBeTrue()->and($kept->getAttribute('needs_attention'))->toBeFalse();
    }
    // Already off: stays off and unflagged, so repairing other channels never turns it on.
    expect($pausedByOperator->refresh()->enabled)->toBeFalse()->and($pausedByOperator->getAttribute('needs_attention'))->toBeFalse();
    $owner = User::factory()->owner()->create();
    Http::preventStrayRequests();
    Http::fake();
    $this->actingAs($owner)->postJson(route('notification-channels.test', $publicHttp))->assertOk()
        ->assertJsonPath('status', 'failed')->assertJsonPath('reason', 'notifications.needs_https');
    Http::assertNothingSent();
    $this->get(route('sites.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->assertOk()
        ->assertJsonCount(2, 'props.notificationAttention')
        ->assertJsonPath('props.notificationAttention.0.reason', __('notifications.needs_https'));

    $this->patchJson(route('notification-channels.update', $publicHttp), ['config' => ['server' => 'http://ntfy.example', 'topic' => 'alerts']])
        ->assertUnprocessable()->assertJsonValidationErrors('values.server');
    $this->patchJson(route('notification-channels.update', $publicHttp), ['config' => ['server' => 'https://ntfy.example', 'topic' => 'alerts']])->assertOk();
    expect($publicHttp->refresh()->enabled)->toBeTrue()->and($publicHttp->getAttribute('needs_attention'))->toBeFalse();

    // The Owner lists the host instead: saving the unchanged settings resumes the channel; a save before did not.
    config(['heptapod.outbound.delivery_private_hosts' => ['ntfy.lan', 'ntfy.office']]);
    $office = NotificationDestination::factory()->create(['channel' => 'ntfy',
        'config' => app(ChannelRegistry::class)->get('ntfy')->configSchema()->validateConfig(['server' => 'http://ntfy.office', 'topic' => 'alerts'])]);
    config(['heptapod.outbound.delivery_private_hosts' => ['ntfy.lan']]);
    $office->forceFill(['enabled' => false, 'needs_attention' => true, 'attention_reason' => 'notifications.needs_https'])->save();
    $this->patchJson(route('notification-channels.update', $office), ['label' => 'Office'])->assertOk();
    expect($office->refresh()->enabled)->toBeFalse()->and($office->getAttribute('needs_attention'))->toBeTrue();
    config(['heptapod.outbound.delivery_private_hosts' => ['ntfy.lan', 'ntfy.office']]);
    $this->patchJson(route('notification-channels.update', $office), ['config' => ['server' => 'http://ntfy.office', 'topic' => 'alerts']])->assertOk();
    expect($office->refresh()->enabled)->toBeTrue()->and($office->getAttribute('needs_attention'))->toBeFalse();
});

it('flags plain-HTTP channels beyond the first page of the upgrade migration', function (): void {
    $plain = new ValidatedConfig(['server' => 'http://ntfy.example', 'topic' => 'alerts'], []);
    NotificationDestination::factory()->count(101)->create(['channel' => 'ntfy', 'config' => $plain]);
    $teams = NotificationDestination::factory()->create(['channel' => 'microsoftTeams', 'config' => new ValidatedConfig([], ['url' => 'http://teams.example/workflow'])]);

    (require database_path('migrations/2026_11_21_130000_flag_plain_http_notification_destinations.php'))->up();

    expect(NotificationDestination::query()->where('needs_attention', true)->count())->toBe(102)
        ->and(NotificationDestination::query()->where('enabled', true)->count())->toBe(0)
        ->and($teams->refresh()->getAttribute('attention_reason'))->toBe('notifications.needs_https');
});
