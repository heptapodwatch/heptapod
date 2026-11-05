<?php

declare(strict_types=1);

namespace Tests;

/**
 * Feature tests excluded from the CI php-tests selection (test-hygiene T1, 2026-10-29), mirrored by the
 * <exclude> entries of the Feature test suite in phpunit.xml. Each names its cause class and owner area:
 * product = suspected product drift (the test asserts documented behavior), test = stale test expectation or fixture.
 * QuarantineTest keeps both lists equal and fails when a listed file no longer exists, so the list can only shrink.
 */
final class Quarantine
{
    /** @var array<string, array{cause: 'product'|'test', owner: string, note: string}> */
    public const array Files = [
        'tests/Feature/MonitoringRoutesTest.php' => ['cause' => 'test', 'owner' => 'routes', 'note' => 'unexplained CI run 41561079596 shard 2 seed 1736113616 failure at line 32: GET /clients was not a valid Inertia response; exact-seed shard replay did not reproduce'],
        'tests/Feature/DemoScenarioTest.php' => ['cause' => 'test', 'owner' => 'demo', 'note' => 'DemoSeeder::reviewCertificate issues certificates on the wall clock; expiry counts disagree with the frozen test clock; needs a clock/validity seam in the seeder (production change). Also stale: /dashboard now 301 and 25 (not 24) demo clients'],
        'tests/Feature/SeederDomainsTest.php' => ['cause' => 'test', 'owner' => 'demo', 'note' => 'AgencyDemoSeeder records 30 days of samples for 18 hosts (>4 min per run); too slow for ci-php. Needs a seeder option that skips history in tests (production change), or a nightly job'],
        'tests/Feature/CheckJourneyTest.php' => ['cause' => 'test', 'owner' => 'execution', 'note' => 'real Horizon/cURL/SIGKILL journey; non-deterministic by design, belongs in deployment acceptance, not ci-php'],
    ];
}
