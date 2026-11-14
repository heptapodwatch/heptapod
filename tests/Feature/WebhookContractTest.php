<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\DeleteSite;
use Heptapod\Actions\SendNotificationDelivery;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\SystemIssue;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\SystemIssueKind;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/*
 * Webhook receivers verify raw bytes (docs/webhook-receivers.md). This golden pins the exact body, header set and
 * signature for every event shape a webhook channel can receive, sent through the real delivery job. Ids come from
 * shared sequences and are replaced by stable placeholders; everything else is compared byte for byte.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T09:15:00Z'));
    config(['app.url' => 'https://monitor.example.test']);
    URL::forceRootUrl('https://monitor.example.test');
    $settings = app(InstanceSettings::class);
    $settings->name = 'Golden Monitor';
    $settings->timezone = 'Europe/Berlin';
    $settings->save();
    Http::preventStrayRequests();
    Http::fake(['https://receiver.example.test/*' => Http::response('', 200)]);
    $alertSettings = app(InstanceSettings::class);
    // The webhook contract, not alert sensitivity: the former team behaviour.
    $alertSettings->alert_confirms_other_checks = false;
    $alertSettings->save();
});

function webhookContractChannel(string $locale): NotificationDestination
{
    return NotificationDestination::factory()->create(['label' => 'Golden receiver', 'channel' => 'webhook', 'locale' => $locale,
        'config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig([
            'url' => 'https://receiver.example.test/hook', 'secret' => 'golden-webhook-secret']),
    ]);
}

function webhookContractSite(string $path, string $name = 'Golden shop'): Site
{
    $client = Client::factory()->create(['name' => 'Golden client', 'timezone' => 'Europe/Berlin']);

    return Site::factory()->for($client)->create(['friendly_name' => $name, 'url' => 'https://shop.golden.example/'.$path.'?token=hidden-site-token']);
}

/** @param array<string, mixed> $attributes */
function webhookContractCheck(Site $site, CheckKey $type, array $attributes = []): Check
{
    return Check::factory()->for($site)->create(['type' => $type, ...$attributes]);
}

/** @return array<string, Closure(NotificationDestination): Event> */
function webhookContractEvents(): array
{
    return [
        'uptime opened' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('opened'), CheckKey::Uptime);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subMinutes(4),
                'first_failure_at' => now()->subMinutes(5), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503]]]);

            return Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.opened', 'cause' => 'failed', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed,
                'payload' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503]]]);
        },
        'uptime reminder' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('reminder'), CheckKey::Uptime);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subHours(5),
                'first_failure_at' => now()->subHours(5)->subMinute(), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 502]]]);

            return Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.still_down', 'cause' => 'failed', 'to_result' => CheckResult::Failed, 'payload' => []]);
        },
        'uptime resolved' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('resolved'), CheckKey::Uptime);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subMinutes(40),
                'first_failure_at' => now()->subMinutes(42), 'resolved_at' => now(), 'evidence' => ['summary' => 'causes.http', 'summary_params' => ['status' => 503]]]);
            webhookContractSentOpener($channel, Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => CheckResult::Failed, 'occurred_at' => now()->subMinutes(40)]));

            return Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.resolved', 'cause' => 'failed', 'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded, 'payload' => []]);
        },
        'check errored' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('errored'), CheckKey::Domain);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'cause' => 'errored', 'opened_at' => now(),
                'first_failure_at' => now()->subMinutes(10), 'evidence' => ['error' => 'rdap_unavailable']]);

            return Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'incident.opened', 'cause' => 'errored', 'to_result' => CheckResult::Errored, 'payload' => []]);
        },
        'warning' => static fn (NotificationDestination $channel): Event => Event::factory()->create(['check_id' => webhookContractCheck(webhookContractSite('warning'), CheckKey::Performance)->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]),
        'warning recovered' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('warning-recovered'), CheckKey::Performance);
            webhookContractSentOpener($channel, Event::factory()->create(['check_id' => $check->id, 'occurred_at' => now()->subHour(),
                'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]));

            return Event::factory()->create(['check_id' => $check->id, 'from_result' => CheckResult::Warning, 'to_result' => CheckResult::Succeeded, 'payload' => []]);
        },
        'expiring certificate' => static fn (NotificationDestination $channel): Event => Event::factory()->create(['check_id' => webhookContractCheck(webhookContractSite('expiring'), CheckKey::CertificateHealth)->id,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]),
        'component failure' => static fn (NotificationDestination $channel): Event => Event::factory()->create(['check_id' => webhookContractCheck(webhookContractSite('component'), CheckKey::Cron)->id,
            'type' => 'cron.task_result_changed', 'component_type' => SuppressionComponent::Cron, 'component_id' => 3,
            'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning,
            'payload' => ['name' => 'Nightly export', 'result' => 'warning', 'from_result' => 'succeeded', 'failure_started_at' => '2026-10-30T09:00:00+00:00']]),
        'component recovered' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('component-recovered'), CheckKey::Cron);
            webhookContractSentOpener($channel, Event::factory()->create(['check_id' => $check->id, 'occurred_at' => now()->subHour(),
                'type' => 'cron.task_result_changed', 'component_type' => SuppressionComponent::Cron, 'component_id' => 4,
                'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning,
                'payload' => ['name' => 'Hourly import', 'result' => 'warning', 'from_result' => 'succeeded']]));

            return Event::factory()->create(['check_id' => $check->id,
                'type' => 'cron.task_result_changed', 'component_type' => SuppressionComponent::Cron, 'component_id' => 4,
                'payload' => ['name' => 'Hourly import', 'result' => 'succeeded', 'from_result' => 'warning',
                    'failure_started_at' => '2026-10-30T08:00:00+00:00', 'recovered_at' => '2026-10-30T09:10:00+00:00']]);
        },
        'addon change' => static fn (NotificationDestination $channel): Event => Event::factory()->create(['check_id' => webhookContractCheck(webhookContractSite('change'), CheckKey::Dns)->id,
            'type' => 'dns.records_changed', 'to_result' => CheckResult::Warning,
            'payload' => ['types' => 'A, MX', 'added' => 2, 'removed' => 1, 'summary' => 'dns.changed', 'summary_params' => []]]),
        'maintenance catch-up' => static function (NotificationDestination $channel): Event {
            $check = webhookContractCheck(webhookContractSite('catch-up'), CheckKey::CertificateHealth);
            $incident = Incident::factory()->for($check)->create(['site_id' => $check->site_id, 'opened_at' => now()->subHour()]);

            $catchUp = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
                'type' => 'maintenance.catch_up', 'cause' => 'failed', 'to_result' => CheckResult::Failed,
                'payload' => ['uptime_episode' => false, 'count' => 2, 'check_keys' => ['certificate_health', 'uptime'], 'since' => '2026-10-30T08:15:00+00:00']]);
            DB::table('maintenance_catch_up_incidents')->insert(['event_id' => $catchUp->id, 'incident_id' => $incident->id]);

            return $catchUp;
        },
        'digest' => static function (NotificationDestination $channel): Event {
            $sourceIds = [];
            foreach (['digest-a', 'digest-b', 'digest-c'] as $path) {
                $warning = Event::factory()->create(['check_id' => webhookContractCheck(webhookContractSite($path), CheckKey::Performance)->id,
                    'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
                $sourceIds[] = Delivery::query()->create(['event_id' => $warning->id, 'destination_id' => $channel->id, 'digest' => true,
                    'next_attempt_at' => now(), 'suppression_evaluated_at' => now()])->id;
            }

            return Event::factory()->create(['check_id' => null, 'site_id' => null, 'type' => 'notification.digest',
                'from_result' => null, 'to_result' => null,
                'payload' => ['count' => 3, 'sites' => 'Golden shop, Other shop', 'remaining_sites' => 1, 'delivery_ids' => $sourceIds]]);
        },
    ];
}

/** Recoveries are only sent after this channel's opener was accepted (claim guard); record that ledger history. */
function webhookContractSentOpener(NotificationDestination $channel, Event $opener): void
{
    Delivery::query()->create(['event_id' => $opener->id, 'destination_id' => $channel->id, 'state' => 'sent', 'attempts' => 1,
        'sent_at' => $opener->occurred_at, 'next_attempt_at' => $opener->occurred_at, 'suppression_evaluated_at' => $opener->occurred_at]);
}

/** @return array{body: string, headers: array<string, string>, signature_valid: bool} */
function webhookContractRequest(Request $request): array
{
    $headers = [];
    $names = array_keys($request->headers());
    sort($names);
    foreach ($names as $name) {
        $headers[$name] = implode(',', $request->header($name));
    }
    $timestamp = $headers['X-Heptapod-Timestamp'];

    return ['body' => $request->body(), 'headers' => $headers,
        'signature_valid' => hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'golden-webhook-secret'), $headers['Signature'])];
}

/**
 * Ids come from shared sequences. Replace them only at their structural positions (the id fields, app links and the
 * delivery headers); the signature covers those ids and is checked for validity instead of pinned.
 *
 * @param  array{body: string, headers: array<string, string>, signature_valid: bool}  $request
 */
function webhookContractGolden(array $request): string
{
    $body = preg_replace(['/"(event_id|destination_id|site_id)":[1-9][0-9]*/', '#\\\\/(incidents|sites)\\\\/[1-9][0-9]*#'],
        ['"$1":{$1}', '\\/$1\\/{id}'], $request['body']) ?? throw new LogicException('Could not normalise the webhook body.');
    if ($request['headers']['Content-Length'] !== (string) strlen($request['body'])) {
        throw new LogicException('Content-Length does not match the signed body.');
    }
    $headers = [...$request['headers'], 'Content-Length' => '{body length}', 'Signature' => '{valid signature}'];
    foreach (['X-Heptapod-Delivery', 'Idempotency-Key'] as $name) {
        $headers[$name] ?? throw new LogicException('Missing webhook header '.$name);
        $headers[$name] = preg_replace('/^[0-9]+:[1-9][0-9]*$/', '{event_id}:{destination_id}', $headers[$name]) ?? throw new LogicException('Could not normalise a header.');
    }

    return json_encode(['body' => $body, 'headers' => $headers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

it('sends byte-identical signed webhook bodies and headers for every event shape', function (string $locale): void {
    $channel = webhookContractChannel($locale);
    $golden = [];
    foreach (webhookContractEvents() as $name => $make) {
        $event = $make($channel);
        $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id,
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        $before = count(Http::recorded());
        app()->call(new SendNotification($delivery->id)->handle(...));
        $requests = Http::recorded();
        expect(count($requests))->toBe($before + 1, $name.' sends exactly one request');
        expect($delivery->refresh()->state)->toBe(DeliveryState::Sent, $name);
        $request = webhookContractRequest($requests[$before][0]);
        expect($request['signature_valid'])->toBeTrue($name.' signature');
        expect($request['headers']['X-Heptapod-Delivery'])->toBe($event->id.':'.$channel->id)
            ->and($request['headers']['Idempotency-Key'])->toBe($event->id.':'.$channel->id);
        $golden[$name] = webhookContractGolden($request);
    }
    $owner = User::factory()->owner()->create();
    $before = count(Http::recorded());
    $this->actingAs($owner)->postJson(route('notification-channels.test', $channel))->assertOk()->assertJsonPath('status', 'sent');
    $request = webhookContractRequest(Http::recorded()[$before][0]);
    expect($request['signature_valid'])->toBeTrue('test signature');
    // The test send carries a random idempotency key by design; pin its shape, not its value.
    expect($request['headers']['Idempotency-Key'])->toMatch('/^test:[0-9a-f-]{36}$/');
    $request['headers']['Idempotency-Key'] = 'test:{uuid}';
    expect($request['headers']['X-Heptapod-Delivery'])->toBe('0:'.$channel->id);
    $golden['test send'] = webhookContractGolden($request);

    expect(implode("\n=====\n", array_map(static fn (string $name, string $text): string => $name."\n".$text, array_keys($golden), $golden)))->toMatchSnapshot();
})->with(['en', 'de']);

it('sends a delivery claimed before its site was deleted with a null site id and link and the deleted-site label', function (string $locale, string $label): void {
    $channel = webhookContractChannel($locale);
    $site = webhookContractSite('deleted-after-claim', 'Gone shop');
    $event = Event::factory()->create(['site_id' => $site->id, 'check_id' => webhookContractCheck($site, CheckKey::Performance)->id,
        'type' => 'check.result_changed', 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning, 'payload' => []]);
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
    // The site is deleted right after the claim commits, before the alert is built: the real race the claim guard cannot see.
    $deleted = false;
    Delivery::updated(static function (Delivery $claimed) use ($delivery, $site, &$deleted): void {
        if ($claimed->id === $delivery->id && $claimed->state === DeliveryState::Sending && ! $deleted) {
            $deleted = true;
            DB::afterCommit(static fn () => app(DeleteSite::class)(Site::query()->findOrFail($site->id)));
        }
    });

    $result = app(SendNotificationDelivery::class)($delivery->id);

    expect($deleted)->toBeTrue()->and(Site::query()->find($site->id))->toBeNull()->and($event->refresh()->site_id)->toBeNull();
    expect($result?->status)->toBe(DeliveryState::Sent)->and($delivery->refresh()->state)->toBe(DeliveryState::Sent)->and($delivery->claim_token)->toBeNull();
    $request = webhookContractRequest(Http::recorded()[0][0]);
    expect($request['signature_valid'])->toBeTrue();
    $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
    expect([$body['site_id'], $body['link'], $body['site_label']])->toBe([null, null, $label])
        ->and($body['site_url'])->not->toContain('hidden-site-token');
})->with(['en' => ['en', 'Deleted site: Gone shop'], 'de' => ['de', 'Gelöschte Website: Gone shop']]);

it('sends System issue webhooks with the alert keys and null site and check fields', function (string $locale): void {
    $channel = webhookContractChannel($locale);
    DB::table('notification_preferences')->where('destination_id', $channel->id)->where('category', 'system')->update(['mode' => 'immediate']);
    $issue = SystemIssue::query()->create(['kind' => SystemIssueKind::BackupFailed, 'scope' => '', 'opened_at' => now()->subHour(),
        'details' => ['disk' => 'backups', 'failed_at' => '2026-10-30T08:15:00+00:00']]);
    $mailer = app(SystemIssueMailer::class);
    $golden = [];
    foreach (['opened' => 'system.issue_opened', 'resolved' => 'system.issue_resolved'] as $name => $type) {
        if ($type === 'system.issue_resolved') {
            $issue->update(['resolved_at' => now()]);
        }
        $event = $mailer->event($issue, $type, now(), $type === 'system.issue_resolved' ? ['probe' => false] : []);
        $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'origin' => 'system',
            'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);
        $before = count(Http::recorded());
        app()->call(new SendNotification($delivery->id)->handle(...));
        expect($delivery->refresh()->state)->toBe(DeliveryState::Sent, $name)->and($delivery->category?->value)->toBe('system');
        $request = webhookContractRequest(Http::recorded()[$before][0]);
        expect($request['signature_valid'])->toBeTrue($name.' signature');
        $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        // Same keys as every alert payload; the instance is the subject, so site and check fields are null.
        expect(array_keys($body))->toBe(['event_id', 'destination_id', 'severity', 'cause', 'title', 'summary', 'headline', 'changes', 'details',
            'site_id', 'site_label', 'site_url', 'check_type', 'also_affected', 'rechecking', 'since', 'downtime_seconds', 'link', 'is_test']);
        expect([$body['site_id'], $body['site_label'], $body['site_url'], $body['check_type']])->toBe([null, null, null, null]);
        $golden[$name] = webhookContractGolden($request);
    }

    expect(implode("\n=====\n", array_map(static fn (string $name, string $text): string => $name."\n".$text, array_keys($golden), $golden)))->toMatchSnapshot();
})->with(['en', 'de']);
