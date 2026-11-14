<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Dns\DnsMetrics;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\NotificationDestination;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25T10:00:00Z'));
    config(['mail.default' => 'smtp']);
    Http::preventStrayRequests();
    Http::fake([]);
    $alertSettings = app(InstanceSettings::class);
    // Recovery mail, not alert sensitivity: the former team behaviour.
    $alertSettings->alert_confirms_other_checks = false;
    $alertSettings->save();
});

it('names the resolved opening reason and time in recovery mail without changing its subject', function (CheckKey $type, string $locale, string $subject, ?string $resolved): void {
    $settings = app(InstanceSettings::class);
    $settings->alert_after_minutes = 1;
    $settings->alert_recovery_checks = 1;
    $settings->save();
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $destination = NotificationDestination::factory()->create(['locale' => $locale]);
    foreach ($type === CheckKey::Uptime ? ['site.down', 'site.recovered'] : ['check.failing', 'check.recovered'] as $eventType) {
        notificationRule($destination, $eventType);
    }
    $check = Check::factory()->create(['type' => $type, 'failure_threshold' => 1,
        'config' => app(CheckRegistry::class)->get($type)->definition()->configSchema->validateConfig([])]);
    $check->site->update(['friendly_name' => 'Review mail']);
    $check->site->client->update(['timezone' => 'UTC']);
    $record = function (RunResult $result, string $summary, array $params) use ($check, $type): void {
        $claim = ClaimedRun::start($check, CarbonImmutable::now('UTC')->subSecond());
        $metrics = $type === CheckKey::Dns
            ? new DnsMetrics(1, $result === RunResult::Failed ? 1 : 0, false)
            : new UptimeMetrics(null, null, null, null, null, 0.1,
                array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null), '2026-10-25');
        app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, CarbonImmutable::now('UTC'),
            new CheckOutcome($result, $summary, $params, $metrics)));
    };
    $record(RunResult::Failed, $type === CheckKey::Dns ? 'dns.issue.records_missing' : 'causes.http', $type === CheckKey::Dns ? ['types' => 'AAAA'] : ['status' => 503]);
    dispatchNotifications();
    if ($resolved === null) {
        $incident = $check->incidents()->sole();
        $incident->update(['evidence' => array_diff_key($incident->evidence, ['summary' => true])]);
    }
    $this->travel(7)->minutes();

    $record(RunResult::Succeeded, $type === CheckKey::Dns ? 'dns.succeeded' : 'causes.http', $type === CheckKey::Dns ? [] : ['status' => 200]);
    dispatchNotifications();

    expect($transport->messages())->toHaveCount(2);
    $mail = $transport->messages()->last()->getOriginalMessage();
    expect($mail)->toBeInstanceOf(Email::class);
    expect($mail->getSubject())->toBe($subject);
    if ($resolved === null) {
        expect($mail->getTextBody())->not->toContain('Resolved:', 'no longer retained');
    } else {
        expect($mail->getTextBody())->toContain($resolved, '09:59 UTC –');
        expect($mail->getHtmlBody())->toContain($resolved);
    }
})->with([
    'legacy DNS opening without a reason' => [CheckKey::Dns, 'en', '[Recovered] Review mail — DNS records are unchanged', null],
    'DNS EN' => [CheckKey::Dns, 'en', '[Recovered] Review mail — DNS records are unchanged', 'Resolved: AAAA records are missing, opened Oct 25, 2026, 09:59 UTC.'],
    'DNS DE' => [CheckKey::Dns, 'de', '[Entwarnung] Review mail — DNS-Einträge sind unverändert', 'Behoben: AAAA-Einträge fehlen, Beginn 25. Okt 2026, 09:59 UTC.'],
    'uptime EN' => [CheckKey::Uptime, 'en', '[Recovered] Review mail after 7 min', 'Resolved: HTTP 503 Service Unavailable, opened 09:59 UTC.'],
    'uptime DE' => [CheckKey::Uptime, 'de', '[Entwarnung] Review mail nach 7 Min.', 'Behoben: HTTP 503 Service Unavailable, Beginn 09:59 UTC.'],
]);
