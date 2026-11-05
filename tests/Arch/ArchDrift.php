<?php

declare(strict_types=1);

namespace Tests\Arch;

use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Execution\WorkerMemoryExhausted;
use Heptapod\Console\Commands\BackfillAvatarBacking;
use Heptapod\Console\Commands\RefreshSiteIcons;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\Findings\FindingCause;
use Heptapod\Contracts\Findings\FindingsSourceGroup;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\Findings\FindingsUnavailableReason;
use Heptapod\Contracts\Findings\FindingsUnavailableStatus;
use Heptapod\Contracts\Http\ConnectionFailureReason;
use Heptapod\Contracts\Mail\AlertMailEvent;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\TargetAvailabilityException;
use Heptapod\Contracts\TlsFailureReason;
use Heptapod\Import\ImportFailure;
use Heptapod\Mail\CapabilityRedactingMailLogger;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\Support\Api\ApiProblem;
use Heptapod\SystemHealth\DiskReadException;
use Heptapod\SystemHealth\DiskReadFailure;
use Heptapod\SystemHealth\SystemIssueKind;

/**
 * Known layering drift that predates the rule being enforced in CI.
 * Each entry names one file, method and call. The rules stay enforced for every other call, and
 * ArchDriftTest fails when an entry no longer matches, so this list can only shrink.
 */
final class ArchDrift
{
    /** Controllers that dispatch jobs instead of delegating to an action. @var list<array{file: string, method: string, call: string}> */
    public const array ControllerDispatch = [
    ];

    /** Controllers and commands that write models or open transactions themselves. @var list<array{file: string, method: string, call: string}> */
    public const array ControllerWrites = [
        ['file' => 'app/Console/Commands/PreviewCheckFreshnessCommand.php', 'method' => 'handle', 'call' => 'transaction'],
        ['file' => 'app/Console/Commands/PruneHistoryCommand.php', 'method' => 'handle', 'call' => 'transaction'],
        ['file' => 'app/Console/Commands/RebalanceCheckScheduleCommand.php', 'method' => 'handle', 'call' => 'transaction'],
        ['file' => 'app/Console/Commands/RebalanceCheckScheduleCommand.php', 'method' => 'handle', 'call' => 'update'],
        ['file' => 'app/Console/Commands/ReclassifyLinksCommand.php', 'method' => 'handle', 'call' => 'transaction'],
        ['file' => 'app/Console/Commands/ReclassifyLinksCommand.php', 'method' => 'reclassify', 'call' => 'transaction'],
        ['file' => 'app/Console/Commands/ReclassifyLinksCommand.php', 'method' => 'reclassify', 'call' => 'update'],
    ];

    /**
     * Read-only findings snapshots: providers hold share locks while paging so retention cannot delete
     * the accepted dataset mid-response. Only these transaction calls are allowed; direct write calls in
     * these methods still fail the guard; providers must stay read-only (sharedLock).
     *
     * @var list<array{file: string, method: string, call: string}>
     */
    public const array ReadSnapshotTransactions = [
        ['file' => 'app/Http/Controllers/Api/FindingsController.php', 'method' => '__invoke', 'call' => 'transaction'],
        ['file' => 'app/Http/Controllers/FindingsExportController.php', 'method' => '__invoke', 'call' => 'transaction'],
    ];

    /** Enums outside Heptapod\Enums beyond the reviewed addon wire enums. @var list<class-string> */
    public const array EnumsOutsideEnums = [
        RunOrigin::class, AvailabilityFailure::class, FindingCause::class,
        FindingStatus::class, FindingsSourceGroup::class, FindingsUnavailableReason::class,
        FindingsUnavailableStatus::class, ConnectionFailureReason::class, AlertMailEvent::class,
        MailTone::class, MailDesign::class, NoResultReason::class, TlsFailureReason::class,
        DiskReadFailure::class, SystemIssueKind::class,
    ];

    /** Throwables outside Heptapod\Exceptions beyond the reviewed addon transport failures. @var list<class-string> */
    public const array ThrowablesOutsideExceptions = [
        ManualRunRefused::class, WorkerMemoryExhausted::class, ReclassifiedCheckError::class,
        TargetAvailabilityException::class, ImportFailure::class, ApiProblem::class,
        DiskReadException::class,
    ];

    /** Console commands without the Command suffix. @var list<class-string> */
    public const array CommandsWithoutSuffix = [BackfillAvatarBacking::class, RefreshSiteIcons::class];

    /** Classes in Heptapod\Mail that are not queued Mailables. @var list<class-string> */
    public const array MailNonMailables = [CapabilityRedactingMailLogger::class];

    /** Classes in Heptapod\Notifications that are not Notifications. @var list<class-string> */
    public const array NotificationsNonNotifications = [AccountMailFactory::class];

    /**
     * Inertia pages rendered without a *PageData contract, keyed by component. Fortify's guest views are a lasting exception;
     * every "todo" entry is an app page still fed a raw array and should move onto its PageData.
     *
     * @var array<string, string>
     */
    public const array PagesWithoutPageData = [
        'auth/login' => 'Fortify guest view callback (FortifyServiceProvider), outside the app shell.',
        'auth/reset-password' => 'Fortify guest view callback (FortifyServiceProvider), outside the app shell.',
        'auth/forgot-password' => 'Fortify guest view callback (FortifyServiceProvider), outside the app shell.',
        'auth/verify-email' => 'Fortify guest view callback (FortifyServiceProvider), outside the app shell.',
        'auth/confirm-password' => 'Fortify guest view callback (FortifyServiceProvider), outside the app shell.',
    ];

    /** Controllers with non-resource public methods: [class => methods]. @var array<string, list<string>> */
    public const array NonResourceControllerMethods = [
    ];

    /**
     * Calls of the given lowercase names in a PHP file, keyed by "method|call". With $afterOperator, only calls that follow
     * ->, ?-> or ::; otherwise any T_STRING, which is how the dispatch rule detects static Job::dispatch().
     *
     * @param  list<string>  $names
     * @return list<array{method: string, call: string, line: int}>
     */
    public static function calls(string $path, array $names, bool $afterOperator): array
    {
        $calls = [];
        $method = '';
        $previous = null;
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING && $previous === T_FUNCTION) {
                $method = $token[1];
            } elseif (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), $names, true)
                && (! $afterOperator || in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true))) {
                $calls[] = ['method' => $method, 'call' => strtolower($token[1]), 'line' => $token[2]];
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = is_array($token) ? $token[0] : $token;
        }

        return $calls;
    }

    /** @param list<array{file: string, method: string, call: string}> $entries */
    public static function allowed(array $entries, string $relative, string $method, string $call): bool
    {
        return array_any($entries, static fn (array $entry): bool => $entry['file'] === $relative && $entry['method'] === $method && $entry['call'] === $call);
    }
}
