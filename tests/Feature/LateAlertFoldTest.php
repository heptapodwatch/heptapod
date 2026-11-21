<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Uptime\UptimeEvidence;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

require_once __DIR__.'/../Support/NotificationChannels.php';

function recordFoldUptimeRun(Check $check, RunResult $result, CarbonImmutable $at): void
{
    test()->travelTo($at);
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);
    $context = ClaimedRun::start($check, $at->subSecond());
    app(TransactionalResultRecorder::class)->record(new RecordableRun(
        $check->id,
        $context->claimToken,
        $context->startedAt,
        $at,
        new CheckOutcome(
            $result,
            $result === RunResult::Failed ? 'causes.http' : 'uptime.succeeded',
            $result === RunResult::Failed ? ['status' => 503] : [],
            new UptimeMetrics(null, null, null, null, null, 0.2, $curl, $at->toIso8601String()),
            new UptimeEvidence($result === RunResult::Failed ? 503 : 200, $result === RunResult::Failed ? ['status'] : []),
        ),
    ));
}

it('folds a late Down opener sent after confirmed recovery into one message per channel across mail, Slack and webhook', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    Http::preventStrayRequests();
    Http::fake([
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.1']),
        'https://receiver.test/alerts' => Http::response([], 200),
    ]);

    $mailEn = NotificationDestination::factory()->create(['label' => 'Ops EN', 'locale' => 'en', 'timezone' => 'UTC']);
    $mailDe = NotificationDestination::factory()->create(['label' => 'Ops DE', 'locale' => 'de', 'timezone' => 'UTC']);
    $slack = NotificationDestination::factory()->create([
        'label' => 'Ops Slack', 'channel' => 'slack', 'locale' => 'en', 'timezone' => 'UTC',
        'config' => app(ChannelRegistry::class)->get('slack')->configSchema()->validateConfig(['channel' => 'C123', 'token' => 'xoxb-late-fold-token']),
    ]);
    $webhook = notificationWebhook();
    $webhook->update(['locale' => 'en', 'timezone' => 'UTC']);

    foreach ([$mailEn, $mailDe, $slack, $webhook] as $channel) {
        notificationRoute($channel, [NotificationCategory::Outage, NotificationCategory::Recovery]);
    }

    $site = Site::factory()->create([
        'friendly_name' => 'Praxis Weiss',
        'url' => 'https://praxis-weiss.example.test',
        'alert_after_minutes' => 5,
        'alert_recovery_checks' => 3,
        'alert_confirms_other_checks' => false,
        'alert_reminders' => 'standard',
    ]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'failure_threshold' => 1]);

    // Outage starts at 00:21 UTC (alert due at 00:25), lasts until 02:32 UTC; notifier was down the whole time.
    recordFoldUptimeRun($check, RunResult::Failed, CarbonImmutable::parse('2026-11-21T00:21:01Z'));
    recordFoldUptimeRun($check, RunResult::Succeeded, CarbonImmutable::parse('2026-11-21T02:32:00Z'));

    // Notifier wakes up at 02:32:30 (only 1 of 3 recovery checks done): opener waits for confirmation, sends nothing yet.
    $this->travelTo(CarbonImmutable::parse('2026-11-21T02:32:30Z'));
    dispatchNotifications();
    expect($transport->messages())->toHaveCount(0);
    Http::assertNothingSent();

    // Second and third good checks confirm recovery: exactly ONE folded message goes out per channel.
    recordFoldUptimeRun($check, RunResult::Succeeded, CarbonImmutable::parse('2026-11-21T02:33:00Z'));
    expect($transport->messages())->toHaveCount(0);
    recordFoldUptimeRun($check, RunResult::Succeeded, CarbonImmutable::parse('2026-11-21T02:34:00Z'));

    // Run another dispatch pass after recovery to ensure no duplicate Recovered alert follows.
    $this->travelTo(CarbonImmutable::parse('2026-11-21T02:36:00Z'));
    dispatchNotifications();

    // Mail: 1 EN + 1 DE, never 2 per channel.
    expect($transport->messages())->toHaveCount(2);
    $mails = collect($transport->messages())->mapWithKeys(function ($sent): array {
        $message = $sent->getOriginalMessage();
        expect($message)->toBeInstanceOf(Email::class);

        return [$sent->getEnvelope()->getRecipients()[0]->getAddress() => $message];
    });
    $enEmail = $mails[$mailEn->config['email']];
    $deEmail = $mails[$mailDe->config['email']];
    expect($enEmail->getSubject())->toBe('[Recovered] Praxis Weiss was down 00:21–02:32 (2 h 11 min)')
        ->and($enEmail->getTextBody())->toContain('Praxis Weiss was down 00:21–02:32 (2 h 11 min) and is up again.');
    expect($deEmail->getSubject())->toBe('[Entwarnung] Praxis Weiss war ausgefallen 00:21–02:32 (2 Std. 11 Min.)')
        ->and($deEmail->getTextBody())->toContain('Praxis Weiss war von 00:21 bis 02:32 (2 Std. 11 Min.) ausgefallen und ist wieder erreichbar.');

    // Slack: 1 message with combined "was down HH:MM–HH:MM (duration), up again" title.
    $slackRequests = Http::recorded(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://slack.com/'))->values();
    expect($slackRequests)->toHaveCount(1);
    $slackPayload = json_decode($slackRequests[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);
    expect($slackPayload['text'])->toContain('Praxis Weiss was down 00:21–02:32 (2 h 11 min), up again');

    // Webhook: 1 delivery with severity recovered, downtime_seconds, and combined title.
    $webhookRequests = Http::recorded(fn (HttpRequest $request): bool => $request->url() === 'https://receiver.test/alerts')->values();
    expect($webhookRequests)->toHaveCount(1);
    $payload = json_decode($webhookRequests[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['severity'])->toBe('recovered')
        ->and($payload['cause'])->toBeNull()
        ->and($payload['downtime_seconds'])->toBe(7860)
        ->and($payload['title'])->toBe('Praxis Weiss was down 00:21–02:32 (2 h 11 min), up again');

    // Every recovery delivery was suppressed as folded into the late opener.
    $resolvedEvent = Event::query()->where('type', 'incident.resolved')->sole();
    $resolvedDeliveries = Delivery::query()->where('event_id', $resolvedEvent->id)->get();
    expect($resolvedDeliveries)->toHaveCount(4);
    foreach ($resolvedDeliveries as $delivery) {
        expect($delivery->suppressed)->toBeTrue()
            ->and($delivery->suppression_reason)->toBe(UptimeAlertEpisode::FoldedSuppressionReason);
    }
});

it('still sends a normal Recovered alert to a channel that already received Down before recovery', function (): void {
    config(['mail.default' => 'smtp']);
    Http::preventStrayRequests();

    $earlyChannel = NotificationDestination::factory()->create(['label' => 'Early', 'locale' => 'en', 'timezone' => 'UTC']);
    $lateChannel = NotificationDestination::factory()->create(['label' => 'Late', 'locale' => 'en', 'timezone' => 'UTC']);
    notificationRoute($earlyChannel, [NotificationCategory::Outage, NotificationCategory::Recovery]);
    notificationRoute($lateChannel, [NotificationCategory::Outage, NotificationCategory::Recovery]);

    $transport = new class($lateChannel->config['email']) implements TransportInterface
    {
        public bool $failLate = true;

        /** @var array<string, list<string>> */
        public array $subjectsByRecipient = [];

        public function __construct(private readonly string $lateEmail) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
        {
            $envelope ??= Envelope::create($message);
            $to = $envelope->getRecipients()[0]->getAddress();
            if ($this->failLate && $to === $this->lateEmail) {
                throw new TransportException('Temporary 421 failure');
            }
            if ($message instanceof Email) {
                $this->subjectsByRecipient[$to][] = (string) $message->getSubject();
            }

            return new SentMessage($message, $envelope);
        }

        public function __toString(): string
        {
            return 'smtp://split-test';
        }
    };
    Mail::mailer()->setSymfonyTransport($transport);

    $site = Site::factory()->create([
        'friendly_name' => 'Split Channel Site',
        'url' => 'https://split.example.test',
        'alert_after_minutes' => 2,
        'alert_recovery_checks' => 1,
        'alert_confirms_other_checks' => false,
        'alert_reminders' => 'standard',
    ]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'failure_threshold' => 1]);

    // Outage opens at 10:00; at 10:02 early channel delivers Down while late channel hits a transient failure.
    recordFoldUptimeRun($check, RunResult::Failed, CarbonImmutable::parse('2026-11-21T10:00:01Z'));
    $this->travelTo(CarbonImmutable::parse('2026-11-21T10:02:00Z'));
    dispatchNotifications();
    expect($transport->subjectsByRecipient[$earlyChannel->config['email']] ?? [])->toBe([
        '[Down] Split Channel Site — HTTP 503 since 10:00 UTC',
    ])->and($transport->subjectsByRecipient[$lateChannel->config['email']] ?? [])->toBe([]);

    // Site recovers at 10:02:30 before the late channel's 1-minute retry at 10:03; transport works again.
    recordFoldUptimeRun($check, RunResult::Succeeded, CarbonImmutable::parse('2026-11-21T10:02:30Z'));
    $transport->failLate = false;
    $this->travelTo(CarbonImmutable::parse('2026-11-21T10:05:00Z'));
    dispatchNotifications();
    $this->travelTo(CarbonImmutable::parse('2026-11-21T10:07:00Z'));
    dispatchNotifications();

    expect($transport->subjectsByRecipient[$earlyChannel->config['email']])->toBe([
        '[Down] Split Channel Site — HTTP 503 since 10:00 UTC',
        '[Recovered] Split Channel Site after 3 min',
    ]);
    expect($transport->subjectsByRecipient[$lateChannel->config['email']])->toBe([
        '[Recovered] Split Channel Site was down 10:00–10:02 (3 min)',
    ]);
});

it('sends a real Down alert instead of folding if the site fails again before recovery is confirmed', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::mailer()->setSymfonyTransport($transport = new ArrayTransport);
    Http::preventStrayRequests();

    $mail = NotificationDestination::factory()->create(['label' => 'Ops EN', 'locale' => 'en', 'timezone' => 'UTC']);
    notificationRoute($mail, [NotificationCategory::Outage, NotificationCategory::Recovery]);

    $site = Site::factory()->create([
        'friendly_name' => 'Flap Site',
        'url' => 'https://flap.example.test',
        'alert_after_minutes' => 5,
        'alert_recovery_checks' => 3,
        'alert_confirms_other_checks' => false,
        'alert_reminders' => 'standard',
    ]);
    $check = Check::factory()->for($site)->create(['type' => CheckKey::Uptime, 'failure_threshold' => 1]);

    recordFoldUptimeRun($check, RunResult::Failed, CarbonImmutable::parse('2026-11-21T08:00:01Z'));
    recordFoldUptimeRun($check, RunResult::Succeeded, CarbonImmutable::parse('2026-11-21T08:20:00Z'));

    // Only 1 good check so far: late opener waits for confirmation instead of falsely claiming recovery.
    $this->travelTo(CarbonImmutable::parse('2026-11-21T08:20:30Z'));
    dispatchNotifications();
    expect($transport->messages())->toHaveCount(0);

    // Site fails again before 3 good checks: episode continues and sends a real Down alert.
    recordFoldUptimeRun($check, RunResult::Failed, CarbonImmutable::parse('2026-11-21T08:21:00Z'));
    dispatchNotifications();

    expect($transport->messages())->toHaveCount(1);
    expect($transport->messages()[0]->getOriginalMessage()->getSubject())
        ->toBe('[Down] Flap Site — HTTP 503 since 08:00 UTC');
});
