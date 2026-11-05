<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertNotification;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\Notifications\ChannelRecipient;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\ChannelType;
use Heptapod\Contracts\Notifications\DeliveryResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\Notifications\DestinationConfig;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/NotificationChannels.php';

/** One synchronous send through the mail driver, as the delivery job does. */
function mailpitSend(ChannelType $channel, AlertMessage $message, DestinationConfig $config, string $idempotencyKey): DeliveryResult
{
    return $channel->send(ChannelRecipient::forChannel($config), new AlertNotification($message, $idempotencyKey));
}

it('delivers real localized incident mails with instance branding to mailpit and restores the process locale', function (string $locale, string $state): void {
    app()->setLocale('en');
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025]);
    $instance = app(InstanceSettings::class);
    $instance->name = 'Operations & Monitoring';
    $instance->timezone = 'Europe/Berlin';
    $instance->save();
    $recipient = 'notify-polish-'.Str::uuid().'@example.com';
    $channel = app(ChannelRegistry::class)->get('mail');
    $destination = NotificationDestination::factory()->create(['locale' => $locale,
        'config' => $channel->configSchema()->validateConfig(['email' => $recipient])]);
    // A real uptime incident event, built for this mail destination as the delivery job does.
    test()->travelTo(CarbonImmutable::parse('2026-10-02T04:39:47Z'));
    $opened = notificationIncidentEvent(['cause' => $state === 'errored' ? 'errored' : 'failed', 'to_result' => $state === 'errored' ? CheckResult::Errored : CheckResult::Failed,
        'payload' => $state === 'errored' ? ['result' => 'errored-or-timed-out'] : []]);
    $opened->site->update(['friendly_name' => 'example.com', 'url' => 'https://example.com/health_check']);
    $opened->incident->update(['cause' => $state === 'errored' ? 'errored' : 'failed', 'opened_at' => now(), 'first_failure_at' => now()]);
    $event = $opened;
    if ($state === 'recovered') {
        test()->travel(90)->seconds();
        $event = notificationRecoveryEvent($opened);
    }
    $message = app(NotificationMessage::class)->build($event->refresh(), $destination);
    $result = mailpitSend($channel, $message, $destination->destinationConfig(), $event->id.':'.$destination->id);
    expect($result->status)->toBe(DeliveryState::Sent)->and($result->providerMessageId)->not->toBeNull();
    $search = Http::get('http://mailpit:8025/api/v1/search', ['query' => 'to:'.$recipient])->throw()->json();
    expect($search['messages'])->toHaveCount(1);
    $mail = Http::get('http://mailpit:8025/api/v1/message/'.$search['messages'][0]['ID'])->throw()->json();
    if (is_string($evidence = getenv('HEPTAPOD_MAILPIT_EVIDENCE'))) {
        file_put_contents($evidence.'/'.$locale.'-'.$state.'.html', $mail['HTML']);
        file_put_contents($evidence.'/'.$locale.'-'.$state.'.txt', $mail['Text']);
    }
    $subject = match ([$locale, $state]) {
        ['en', 'failed'] => '[Down] example.com — HTTP 503 since 06:39 CEST',
        ['de', 'failed'] => '[Ausgefallen] example.com — HTTP 503 seit 06:39 CEST',
        ['en', 'recovered'] => '[Recovered] example.com after 2 min',
        ['de', 'recovered'] => '[Entwarnung] example.com nach 2 Min.',
        ['en', 'errored'] => '[Check error] example.com — the Uptime check could not run',
        ['de', 'errored'] => '[Prüffehler] example.com — Prüfung „Erreichbarkeit“ konnte nicht ausgeführt werden',
    };
    $incident = route('incidents.show', ['incident' => $event->incident_id]);
    $open = $locale === 'en' ? 'Open incident #'.$event->incident_id : 'Störung #'.$event->incident_id.' öffnen';
    expect($mail['Subject'])->toBe($subject);
    expect($mail['HTML'])->toContain('lang="'.$locale.'"', 'example.com', $incident, 'Operations &amp; Monitoring', $open)
        ->not->toContain('All rights reserved.', 'Alle Rechte vorbehalten.');
    expect($mail['Text'])->toContain('Operations & Monitoring', $open, $incident)
        ->not->toContain('2026-10-02T04:39:47', 'example.com/health\\_check');
    expect(app()->getLocale())->toBe('en');
})->with(['en', 'de'])->with(['failed', 'recovered', 'errored'])->group('mailpit')->skip(fn (): bool => getenv('HEPTAPOD_MAILPIT_TEST') !== '1', 'Run with HEPTAPOD_MAILPIT_TEST=1 and the Compose mailpit service.');

it('returns a failed delivery when SMTP connection is rejected', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    $channel = app(ChannelRegistry::class)->get('mail');
    $destination = NotificationDestination::factory()->create(['locale' => 'en', 'config' => $channel->configSchema()->validateConfig(['email' => 'operator@example.com'])]);
    // The channel test send's message: its block mail comes from the account mail factory, as in SendTestNotification.
    $message = new AlertMessage(
        42, AlertSeverity::Info, 'notifications.test_title', [], 'notifications.test_summary', [],
        0, 'Heptapod', 'https://heptapod.example', null, CarbonImmutable::now('UTC'), null, null, 'https://heptapod.example', true,
        mail: app(AccountMailFactory::class)->test($destination, User::factory()->create(), CarbonImmutable::now('UTC')),
    );
    $result = mailpitSend($channel, $message, $destination->destinationConfig(), '42:'.$destination->id);
    expect($result->status)->toBe(DeliveryState::Failed)->and($result->reason)->toBe('notifications.transport_failed');
});
