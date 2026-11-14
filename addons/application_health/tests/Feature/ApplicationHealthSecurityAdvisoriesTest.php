<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->has('history.components.0.advisories', 15)
            ->where('history.components.0.advisories.0', [
                'package' => 'dompdf/dompdf', 'advisory_id' => 'CVE-2026-59943',
                'title' => 'Dompdf: Embedded SVG images can leak existence of files and directories within the filesystem',
                'link' => 'https://github.com/advisories/GHSA-j8qw-6jw8-r297', 'cve' => 'CVE-2026-59943', 'severity' => 'medium',
                'affected_versions' => '<3.1.6', 'reported_at' => '2026-07-22T22:52:44+00:00',
            ])
            // An advisory without a CVE is identified by its link.
            ->where('history.components.0.advisories.9.package', 'phenx/php-svg-lib')
            ->where('history.components.0.advisories.9.cve', null)
            ->where('history.components.0.advisories.9.advisory_id', 'https://github.com/advisories/GHSA-97m3-52wr-xvv2')
            ->where('history.components.1.name', 'Database')
            ->where('history.components.1.advisories', null)
            ->where('history.more_advisories', false));
});
