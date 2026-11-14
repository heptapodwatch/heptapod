<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Sitemap\SitemapMetrics;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Events\EventOutbox;
use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Testing\ClaimedRun;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    config(['mail.default' => 'smtp']);
    Http::preventStrayRequests();
    Http::fake([]);
    $alertSettings = app(InstanceSettings::class);
    // Payload redaction, not alert sensitivity: the former team behaviour.
    $alertSettings->alert_confirms_other_checks = false;
    $alertSettings->save();
});

/** Records one errored crawl run whose cause names a discovered URL, then renders the event in Activity and the alert mail. */
function recordRedirectCause(string $siteUrl, string $targetUrl): array
{
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    notificationRule(NotificationDestination::factory()->create(['locale' => 'en']), 'check.failing');
    $check = Check::factory()->create(['type' => CheckKey::BrokenLinks, 'failure_threshold' => 1,
        'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig([])]);
    $check->site->update(['url' => $siteUrl, 'friendly_name' => 'Redaction shop']);
    $claim = ClaimedRun::start($check, CarbonImmutable::now()->subSecond());
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, CarbonImmutable::now(),
        new CheckOutcome(RunResult::Errored, 'crawl.start_redirect_off_site', ['start_redirect_target_url' => $targetUrl], null, error: new CheckError(RuntimeException::class, 'Start URL redirects off site.'))));
    dispatchNotifications();
    $owner = User::factory()->owner()->create();
    $message = null;
    test()->actingAs($owner)->get('/activity?period=all')->assertOk()->assertInertia(function (Assert $page) use (&$message): Assert {
        $message = json_encode($page->toArray()['props']['events'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $page;
    });
    $mail = $transport->messages()->sole()->getOriginalMessage();
    expect($mail)->toBeInstanceOf(Email::class);
    $payload = json_encode(Event::query()->where('type', 'incident.opened')->sole()->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    // Deleting the site scrubs retained payloads again and leaves Activity without a live check to consult.
    test()->delete('/sites/'.$check->site_id, ['name' => 'Redaction shop'])->assertRedirect();
    $deletedActivity = null;
    test()->get('/activity?period=all')->assertOk()->assertInertia(function (Assert $page) use (&$deletedActivity): Assert {
        $deletedActivity = json_encode($page->toArray()['props']['events'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $page;
    });

    return [
        'payload' => $payload,
        'activity' => (string) $message,
        'mail' => $mail->getTextBody(),
        'deleted-site payload' => json_encode(Event::query()->where('type', 'incident.opened')->sole()->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'deleted-site activity' => (string) $deletedActivity,
    ];
}

it('keeps a discovered URL query readable in the event, Activity and the alert', function (): void {
    $surfaces = recordRedirectCause('https://fixtures.test/', 'https://other.fixtures.test/a?page=2');

    expect($surfaces)->toHaveKeys(['payload', 'activity', 'mail', 'deleted-site payload', 'deleted-site activity']);
    foreach ($surfaces as $text) {
        expect($text)->toContain('https://other.fixtures.test/a?page=2')->not->toContain('[redacted]');
    }
});

it('still masks a query secret from the configured site URL in the event, Activity and the alert', function (): void {
    $surfaces = recordRedirectCause('https://fixtures.test/?key=abcd1234', 'https://other.fixtures.test/landing?key=abcd1234');

    expect($surfaces)->toHaveKeys(['payload', 'activity', 'mail', 'deleted-site payload', 'deleted-site activity']);
    foreach ($surfaces as $text) {
        expect($text)->toContain('https://other.fixtures.test/landing?', '[redacted]')->not->toContain('abcd1234');
    }
});

it('masks credential-named query values in discovered URLs while keeping other parameters readable', function (string $target, string $masked, string $secret): void {
    $surfaces = recordRedirectCause('https://fixtures.test/', $target);

    expect($surfaces)->toHaveKeys(['payload', 'activity', 'mail', 'deleted-site payload', 'deleted-site activity']);
    foreach ($surfaces as $text) {
        expect($text)->toContain($masked)->not->toContain($secret);
    }
})->with([
    'crawl redirect login token' => ['https://other.fixtures.test/login?token=wild-token-1234&page=2', 'https://other.fixtures.test/login?token=[redacted]&page=2', 'wild-token-1234'],
    'signed sitemap URL' => ['https://cdn.fixtures.test/s.xml?X-Amz-Signature=deadbeef5678&X-Amz-Date=20261029', 'https://cdn.fixtures.test/s.xml?X-Amz-Signature=[redacted]&X-Amz-Date=20261029', 'deadbeef5678'],
]);

it('masks a signed sitemap issue URL in the issues event, Activity and the alert', function (): void {
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    notificationRule(NotificationDestination::factory()->create(['locale' => 'en']), 'sitemap.issues_changed');
    $listed = 'https://cdn.fixtures.test/page?X-Amz-Signature=deadbeef5678&page=2';
    $check = Check::factory()->create(['type' => CheckKey::Sitemap,
        'config' => app(CheckRegistry::class)->get(CheckKey::Sitemap)->definition()->configSchema->validateConfig([])]);
    $check->site->update(['url' => 'https://fixtures.test/', 'friendly_name' => 'Redaction shop']);
    $record = function (array $issues) use ($check): void {
        $claim = ClaimedRun::start($check, CarbonImmutable::now()->subSecond());
        app(ResultRecorder::class)->record(new RecordableRun($check->id, $claim->claimToken, $claim->startedAt, CarbonImmutable::now(),
            new CheckOutcome(RunResult::Failed, 'sitemap.summary.issue', [], new SitemapMetrics(1, 1, true), events: [new AddonEvent('sitemap.issues_changed',
                'sitemap:'.$check->id.':'.count($issues), 1, ['new' => $issues, 'resolved' => []])])));
        $this->travel(1)->minutes();
    };
    $record([['url' => $listed, 'type' => 'sitemap.issue.unreachable', 'kind' => 'http_status', 'status' => 404]]);
    dispatchNotifications();
    $owner = User::factory()->owner()->create();
    $activity = null;
    $this->actingAs($owner)->get('/activity?period=all')->assertOk()->assertInertia(function (Assert $page) use (&$activity): Assert {
        $activity = json_encode($page->toArray()['props']['events'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $page;
    });
    $payload = json_encode(Event::query()->where('type', 'sitemap.issues_changed')->sole()->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    expect($mail)->toBeInstanceOf(Email::class);

    foreach (['payload' => $payload, 'activity' => (string) $activity, 'mail' => $mail->getTextBody()] as $text) {
        expect($text)->not->toContain('deadbeef5678');
    }
    expect($payload)->toContain('https://cdn.fixtures.test/page?X-Amz-Signature=[redacted]&page=2');
});

it('masks secrets in component names on the component path', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::ApplicationHealth,
        'config' => app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema->validateConfig([
            'result_url' => 'http://fixtures.test/application-health/ok', 'secret' => 'component-secret-1234'])]);
    $check->site->update(['url' => 'https://fixtures.test/?key=site-key-5678', 'friendly_name' => 'Redaction shop']);
    Event::factory()->for($check)->create(['type' => 'application_health.component_changed', 'component_type' => SuppressionComponent::ApplicationHealth,
        'component_id' => 7, 'from_result' => 'succeeded', 'to_result' => 'failed',
        'payload' => ['component_id' => 7, 'name' => 'Disk component-secret-1234 site-key-5678 https://other.fixtures.test/a?page=2&token=wild-token-1234 https://other.fixtures.test/b?page=3', 'old_status' => 'ok', 'status' => 'failed', 'endpoint' => 'https://other.fixtures.test/b?page=3']]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/activity?period=all')->assertOk()->assertInertia(function (Assert $page): Assert {
        $activity = json_encode($page->toArray()['props']['events'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        expect($activity)->not->toContain('component-secret-1234', 'site-key-5678', 'wild-token-1234')
            ->toContain('https://other.fixtures.test/a?page=2&token=[redacted]', 'https://other.fixtures.test/b?page=3');

        return $page;
    });
});

it('redacts nested event string values without changing keys, scalar leaves or protocol facts', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'run_headers' => ['X-Secret' => 'display-secret-1234']]);
    $event = app(EventOutbox::class)->record($check, CarbonImmutable::now(), 'check.result_changed', CheckResult::Succeeded, CheckResult::Failed, [
        'summary' => 'display-secret-1234',
        'summary_params' => ['presentation' => 'display-secret-1234', 'message' => 'display-secret-1234'],
        'display-secret-1234' => ['message' => 'display-secret-1234', 'leaves' => [12, 1.5, true, false, null], 'empty' => []],
    ]);

    expect($event?->fresh()->payload)->toEqual([
        'summary' => 'display-secret-1234',
        'summary_params' => ['presentation' => 'display-secret-1234', 'message' => '[redacted]'],
        'display-secret-1234' => ['message' => '[redacted]', 'leaves' => [12, 1.5, true, false, null], 'empty' => []],
    ]);
});

it('masks the site URL query secret in a maintenance catch-up alert', function (): void {
    Http::fake(['https://receiver.test/alerts' => Http::response([], 200)]);
    config(['notifications.renotify_intervals_seconds' => null]);
    notificationRule(notificationWebhook(), 'check.failing');
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    $check->site->update(['url' => 'https://fixtures.test/?shop=site-key-5678&page=2', 'friendly_name' => 'Redaction shop']);
    $window = MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinute(), 'catch_up_at_end' => true]);
    $incident = Incident::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id,
        'opened_at' => now()->subMinutes(30), 'opened_during_maintenance_window_id' => $window->id]);
    Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id, 'incident_id' => $incident->id,
        'type' => 'incident.opened', 'cause' => 'failed', 'to_result' => 'failed', 'suppressed' => true, 'suppression_reason' => 'maintenance']);

    dispatchNotifications();

    $catchUp = Event::query()->where('type', 'maintenance.catch_up')->sole();
    expect($catchUp->payload['uptime_episode'] ?? false)->toBeFalse();
    $sent = Http::recorded()->map(static fn (array $pair): array => json_decode($pair[0]->body(), true, flags: JSON_THROW_ON_ERROR))
        ->firstWhere('event_id', $catchUp->id);
    expect($sent)->not->toBeNull()
        ->and($sent['site_url'])->toStartWith('https://fixtures.test/?')->toContain('[redacted]')
        ->and(json_encode($sent, JSON_THROW_ON_ERROR))->not->toContain('site-key-5678');
});
