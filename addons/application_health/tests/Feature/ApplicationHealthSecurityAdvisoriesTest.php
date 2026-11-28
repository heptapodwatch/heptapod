<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\ClientScope;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/../Pest.php';

/**
 * The real response of spatie/laravel-health 1.41 with spatie/security-advisories-health-check 1.4 on a Laravel 13 app
 * with dompdf/dompdf 2.0.0 and phpmailer/phpmailer 6.4.0, plus a database component.
 *
 * @param  list<string>|null  $packages  Keep only these packages' advisories.
 * @return array<string, mixed>
 */
function spatieAdvisoriesPayload(?array $packages = null, string $status = 'failed'): array
{
    $payload = json_decode(file_get_contents(__DIR__.'/../Fixtures/spatie-security-advisories.json'), true, flags: JSON_THROW_ON_ERROR);
    $payload['finishedAt'] = time();
    $advisories = &$payload['checkResults'][0];
    if ($packages !== null) {
        $advisories['meta'] = array_intersect_key($advisories['meta'], array_flip($packages));
    }
    if ($status !== 'failed') {
        $advisories = [...$advisories, 'status' => $status, 'notificationMessage' => 'No security vulnerability advisories found', 'shortSummary' => 'Ok', 'meta' => []];
    }
    $payload['checkResults'][] = ['name' => 'Database', 'label' => 'Database', 'status' => 'ok', 'notificationMessage' => '', 'shortSummary' => 'Ok', 'meta' => []];

    return $payload;
}

function advisoriesSiteCheck(Site $site): Check
{
    return applicationHealthCheck(['result_url' => 'http://fixtures.test/application-health/'.$site->id], $site);
}

/** @param array<string, mixed>|FetchResult $payload */
function runAdvisories(Check $check, array|FetchResult $payload): void
{
    respondApplicationHealth($check, $payload);
    runApplicationHealth($check);
}

/** @return list<int> */
function advisorySiteIds(string $query): array
{
    $ids = [];
    test()->get('/sites?'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$ids): Assert {
        return $page->loadDeferredProps(['list'], function (Assert $list) use (&$ids): Assert {
            $ids = array_column($list->toArray()['props']['sites'], 'id');

            return $list;
        });
    });
    sort($ids);

    return $ids;
}

it('shows the advisories of a failed spatie security advisories result in the check detail', function (): void {
    $check = advisoriesSiteCheck(Site::factory()->create());
    runAdvisories($check, spatieAdvisoriesPayload());
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    expect($check->refresh()->latest_run_result)->toBe(CheckResult::Failed);
    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('history.components.0.name', 'SecurityAdvisories')
            ->where('history.components.0.latest_result', 'failed')
            ->has('history.advisories', 15)
            ->where('history.advisories.0', [
                'package' => 'dompdf/dompdf', 'advisory_id' => 'CVE-2026-59943',
                'title' => 'Dompdf: Embedded SVG images can leak existence of files and directories within the filesystem',
                'link' => 'https://github.com/advisories/GHSA-j8qw-6jw8-r297', 'cve' => 'CVE-2026-59943', 'severity' => 'medium',
                'affected_versions' => '<3.1.6', 'reported_at' => '2026-07-22T22:52:44+00:00',
            ])
            // An advisory without a CVE is identified by its link.
            ->where('history.advisories.9.package', 'phenx/php-svg-lib')
            ->where('history.advisories.9.cve', null)
            ->where('history.advisories.9.advisory_id', 'https://github.com/advisories/GHSA-97m3-52wr-xvv2')
            ->where('history.components.1.name', 'Database')
            ->where('history.advisories_pagination.total', 15)->where('history.advisories_pagination.last_page', 1));
    // The advisories page like every list: the second page of ten holds the last five, in the same order,
    // and stays present when the components' page beyond their end redirects to their last page.
    $this->followingRedirects()->get('/sites/'.$check->site_id.'/checks/'.$check->id.'?health_advisories_per_page=10&health_advisories_page=2&health_components_per_page=10&health_components_page=2')
        ->assertInertia(fn (Assert $page): Assert => $page->has('history.advisories', 5)
            ->where('history.advisories_pagination.total', 15)
            ->where('history.advisories_pagination.last_page', 2));
});

it('stores the advisories of the 1.2 check, which sends null CVE and severity values and extra keys', function (): void {
    // spatie/security-advisories-health-check 1.2 (the last line for PHP 8.2) puts the unfiltered Packagist advisory into
    // the meta: null cve/severity and extra keys such as advisoryId and sources.
    $payload = json_decode(file_get_contents(__DIR__.'/../Fixtures/spatie-security-advisories-1.2.json'), true, flags: JSON_THROW_ON_ERROR);
    $payload['finishedAt'] = time();
    $check = advisoriesSiteCheck(Site::factory()->create());
    runAdvisories($check, $payload);
    $owner = User::factory()->create();
    $owner->assignRole('Owner');

    $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('history.advisories', 2)
            ->where('history.advisories.0.cve', 'CVE-2026-128083')
            ->where('history.advisories.0.severity', 'low')
            ->where('history.advisories.1.cve', null)
            ->where('history.advisories.1.severity', null)
            ->where('history.advisories.1.advisory_id', 'https://github.com/advisories/GHSA-97m3-52wr-xvv2'));
});

it('filters the Sites list by open advisory, package and advisory ID within the viewer scope', function (): void {
    [$agency, $client] = Client::factory()->count(2)->create();
    $all = advisoriesSiteCheck(Site::factory()->for($agency)->create());
    $mailer = advisoriesSiteCheck(Site::factory()->for($client)->create());
    $healthy = advisoriesSiteCheck(Site::factory()->for($client)->create());
    $allPayload = spatieAdvisoriesPayload();
    // dompdf/dompdf ranks by its highest severity, below the critical packages despite its name.
    $allPayload['checkResults'][0]['meta']['dompdf/dompdf'] = array_map(static fn (array $advisory): array => [...$advisory, 'severity' => 'medium'],
        $allPayload['checkResults'][0]['meta']['dompdf/dompdf']);
    runAdvisories($all, $allPayload);
    runAdvisories($mailer, spatieAdvisoriesPayload(['phpmailer/phpmailer']));
    runAdvisories($healthy, spatieAdvisoriesPayload(status: 'ok'));
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($client);
    $phpmailerCve = spatieAdvisoriesPayload()['checkResults'][0]['meta']['phpmailer/phpmailer'][0]['cve'];

    $this->actingAs($owner)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('advisory_packages', [
        ['package' => 'phpmailer/phpmailer', 'sites' => 2, 'severity' => 'critical'],
        ['package' => 'phenx/php-svg-lib', 'sites' => 1, 'severity' => 'critical'],
        ['package' => 'dompdf/dompdf', 'sites' => 1, 'severity' => 'medium'],
    ]));
    expect(advisorySiteIds('filter[advisories]=1'))->toBe([$all->site_id, $mailer->site_id])
        ->and(advisorySiteIds('filter[package]=dompdf/dompdf'))->toBe([$all->site_id])
        ->and(advisorySiteIds('filter[advisory]='.$phpmailerCve))->toBe([$all->site_id, $mailer->site_id])
        ->and(advisorySiteIds('filter[advisory]='.urlencode('https://github.com/advisories/GHSA-97m3-52wr-xvv2')))->toBe([$all->site_id])
        ->and(advisorySiteIds('filter[package]=vendor/unknown'))->toBe([])
        // An advisory link may contain commas; it is one identity, not a list.
        ->and(advisorySiteIds('filter[advisory]='.urlencode('https://example.com/advisory/a,b')))->toBe([]);
    $this->get('/sites?filter[package]=dompdf/dompdf')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.package.value', 'dompdf/dompdf')->where('sites_pagination.total', 1));

    $this->actingAs($member)->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('advisory_packages', [
        ['package' => 'phpmailer/phpmailer', 'sites' => 1, 'severity' => 'critical'],
    ]));
    expect(advisorySiteIds('filter[advisories]=1'))->toBe([$mailer->site_id])
        ->and(advisorySiteIds('filter[package]=dompdf/dompdf'))->toBe([]);
    $this->get('/sites?filter[advisory]='.$phpmailerCve)->assertInertia(fn (Assert $page): Assert => $page->where('sites_pagination.total', 1));

    // A Check error and a skipped advisories result verify nothing, so the advisories stay open; an ok result clears them.
    runAdvisories($mailer, new FetchResult(failure: new FetchFailure(FetchFailureKind::Connect)));
    runAdvisories($all, spatieAdvisoriesPayload(status: 'skipped'));
    $this->actingAs($owner);
    expect(advisorySiteIds('filter[advisories]=1'))->toBe([$all->site_id, $mailer->site_id]);
    runAdvisories($all, spatieAdvisoriesPayload(status: 'ok'));
    expect(advisorySiteIds('filter[advisories]=1'))->toBe([$mailer->site_id]);
    $mailer->update(['enabled' => false]);
    expect(advisorySiteIds('filter[advisories]=1'))->toBe([]);
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->where('advisory_packages', [])->where('more_advisory_packages', false));

    // The picker lists the 100 most severe packages and says when there are more.
    $many = spatieAdvisoriesPayload(['phpmailer/phpmailer']);
    $advisory = $many['checkResults'][0]['meta']['phpmailer/phpmailer'][0];
    $many['checkResults'][0]['meta'] = array_combine(array_map(static fn (int $n): string => sprintf('vendor/package-%03d', $n), range(1, 101)),
        array_map(static fn (int $n): array => [[...$advisory, 'cve' => 'CVE-2026-'.(10000 + $n), 'link' => $advisory['link'].'-'.$n]], range(1, 101)));
    runAdvisories($all, $many);
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page->has('advisory_packages', 100)
        ->where('advisory_packages.0.package', 'vendor/package-001')->where('more_advisory_packages', true));
});

it('names the advisories in the alert mail as code in HTML and as plain names in the text part', function (): void {
    config(['mail.default' => 'smtp']);
    $transport = new ArrayTransport;
    Mail::mailer()->setSymfonyTransport($transport);
    $client = Client::factory()->create(['name' => 'Marktladen']);
    $check = advisoriesSiteCheck(Site::factory()->for($client)->create(['friendly_name' => 'Marktladen']));
    $payload = json_decode(file_get_contents(__DIR__.'/../Fixtures/spatie-security-advisories-1.2.json'), true, flags: JSON_THROW_ON_ERROR);
    // A second failing run confirms the opening alert.
    foreach ([1, 2] as $run) {
        $payload['finishedAt'] = time();
        runAdvisories($check, $payload);
    }
    $channel = NotificationDestination::factory()->create(['channel' => 'mail', 'locale' => 'en',
        'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'ops@example.test'])]);
    $event = Event::query()->where('check_id', $check->id)->where('type', 'incident.opened')->sole();
    $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()]);

    app()->call(new SendNotification($delivery->id)->handle(...));

    expect($delivery->refresh()->state)->toBe(DeliveryState::Sent);
    $mail = $transport->messages()->sole()->getOriginalMessage();
    assert($mail instanceof Email);
    expect($mail->getTextBody())->toContain("Marktladen\n", 'Security advisories | Security advisories found for laravel/framework and phenx/php-svg-lib')
        ->not->toContain('`', 'Marktladen · Marktladen')
        ->and($mail->getHtmlBody())->toContain('>laravel/framework</code>', '>phenx/php-svg-lib</code>', '>Security advisories</span>')
        ->not->toContain('`', 'Marktladen · Marktladen');
});
