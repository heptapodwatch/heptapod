<?php

declare(strict_types=1);

namespace Tests\Arch;

/**
 * Direct row locks in the notification engine that do not go through Heptapod\Events\Notifications\NotificationLocks.
 * Each entry names one file, method and lock and how often that lock occurs there. NotificationLockDriftTest fails on any
 * other direct lock, on a second occurrence beyond the count and on an entry that no longer matches, so this list can only
 * shrink. Every reason is "permanent: <why>", a lock that cannot form a cycle.
 */
final class NotificationLockDrift
{
    /** @var list<string> */
    public const array Roots = ['app/Actions', 'app/Events/Notifications', 'app/SystemHealth', 'app/Incidents', 'app/Checks/Recording', 'addons/channels'];

    public const string Hierarchy = 'app/Events/Notifications/NotificationLocks.php';

    /** @var list<array{file: string, method: string, lock: string, count: int, reason: string}> */
    public const array Allowed = [
        ['file' => 'app/Actions/AnnounceMaintenanceBoundaries.php', 'method' => '__invoke', 'lock' => 'FOR UPDATE', 'count' => 1, 'reason' => 'permanent: single level (windows FOR UPDATE SKIP LOCKED, marker writes only)'],
        ['file' => 'app/Actions/CancelCheckRun.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: single level (checks FOR UPDATE; writes only the check and its attempt)'],
        ['file' => 'app/Actions/DeleteCronTask.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: single level (checks FOR UPDATE; cron tasks and run requests of that check only)'],
        ['file' => 'app/Actions/EnableCheck.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: single level (checks FOR UPDATE; writes only the check)'],
        ['file' => 'app/Actions/Notifications/DismissNotificationMigrationReport.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: out of the engine (newest activity_log report row serialises dismissals; writes only an audit row)'],
        ['file' => 'app/Actions/Notifications/UpdateNotificationSchedule.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: single level (one notification_schedules row, not an engine level)'],
        ['file' => 'app/Actions/RequestBackup.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: out of the engine (Owner role row serialises backup requests)'],
        ['file' => 'app/Actions/UpdateClient.php', 'method' => '__invoke', 'lock' => 'FOR NO KEY UPDATE', 'count' => 1, 'reason' => 'permanent: above level 1 (client row and its media only)'],
        ['file' => 'app/Actions/UpdateCronTask.php', 'method' => '__invoke', 'lock' => 'lockforupdate', 'count' => 1, 'reason' => 'permanent: single level (checks FOR UPDATE; cron tasks of that check only)'],
    ];

    /**
     * Direct row locks in a PHP file: lockForUpdate() and sharedLock() calls and string literals with a FOR UPDATE,
     * FOR NO KEY UPDATE, FOR SHARE or FOR KEY SHARE clause. The lock is the lowercase call name or the clause in upper case.
     *
     * @return list<array{method: string, lock: string, line: int}>
     */
    public static function locks(string $path): array
    {
        $locks = array_map(static fn (array $call): array => ['method' => $call['method'], 'lock' => $call['call'], 'line' => $call['line']],
            ArchDrift::calls($path, ['lockforupdate', 'sharedlock'], true));
        $method = '';
        $previous = null;
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $previous === T_FUNCTION) {
                $method = $token[1];
            } elseif (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\bFOR\s+(NO\s+KEY\s+UPDATE|KEY\s+SHARE|UPDATE|SHARE)\b/i', $token[1], $match) === 1) {
                $locks[] = ['method' => $method, 'lock' => 'FOR '.strtoupper((string) preg_replace('/\s+/', ' ', $match[1])), 'line' => $token[2]];
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = is_array($token) ? $token[0] : $token;
        }

        return $locks;
    }

    /** The allowed occurrences of one direct lock in a file and method: 0 when no entry names it. */
    public static function allowedCount(string $relative, string $method, string $lock): int
    {
        $entry = array_find(self::Allowed, static fn (array $entry): bool => $entry['file'] === $relative && $entry['method'] === $method && $entry['lock'] === $lock);

        return $entry['count'] ?? 0;
    }

    /**
     * The direct locks of a file grouped by method and lock, with their occurrence count and lines.
     *
     * @return array<string, array{method: string, lock: string, count: int, lines: list<int>}>
     */
    public static function occurrences(string $path): array
    {
        $grouped = [];
        foreach (self::locks($path) as $lock) {
            $key = $lock['method'].' '.$lock['lock'];
            $grouped[$key] ??= ['method' => $lock['method'], 'lock' => $lock['lock'], 'count' => 0, 'lines' => []];
            $grouped[$key]['count']++;
            $grouped[$key]['lines'][] = $lock['line'];
        }

        return $grouped;
    }
}
