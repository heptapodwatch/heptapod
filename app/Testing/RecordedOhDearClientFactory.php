<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\OhDear\OhDearClientFactory;
use OhDear\PhpSdk\OhDear;

/** Local dev stacks answer the import from a recorded account directory instead of Oh Dear (OHDEAR_IMPORT_FIXTURE). */
final readonly class RecordedOhDearClientFactory implements OhDearClientFactory
{
    public function __construct(private string $directory) {}

    public function make(#[\SensitiveParameter] string $token, bool $dryRun): OhDear
    {
        // `4` fails monitor page 4 for plans and imports; `import:4` only for imports (a complete plan, then a partial import).
        $failedPage = config('services.ohdear.import_fixture_failed_page');
        $failedPage = is_scalar($failedPage) ? (string) $failedPage : '';
        $importOnly = str_starts_with($failedPage, 'import:');
        $failedPage = $importOnly ? substr($failedPage, strlen('import:')) : $failedPage;
        // An API path (e.g. /api/monitors/504/cron-checks) answers HTTP 500: during imports only, or with `plan:` during plans only.
        $failedPath = config('services.ohdear.import_fixture_failed_path');
        $failedPath = is_string($failedPath) ? $failedPath : '';
        $planOnly = str_starts_with($failedPath, 'plan:');
        $failedPath = $planOnly ? substr($failedPath, strlen('plan:')) : $failedPath;
        $endpointStatuses = $failedPath !== '' && $dryRun === $planOnly ? [$failedPath => 500] : [];
        // `import` adds a monitor (id 506) that only the import sees, as if it was created after the plan.
        $addedMonitor = config('services.ohdear.import_fixture_added_monitor') === 'import' && ! $dryRun;
        $sdk = new OhDear($token);
        $sdk->withMockClient(OhDearAccountTransport::recorded($this->directory,
            is_numeric($failedPage) && ($dryRun === false || ! $importOnly) ? (int) $failedPage : null, $endpointStatuses, $addedMonitor));

        return $sdk;
    }
}
