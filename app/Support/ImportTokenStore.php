<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Heptapod\Models\ImportRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The only place an import source token lives: encrypted in the cache, keyed by its import run (plan), for a
 * short TTL between the plan and the import. Never in the database, the session, a queue payload or a log.
 * Access revocation and profile deletion purge every token of a user via the user's durable import_runs rows.
 */
final class ImportTokenStore
{
    public const int TtlMinutes = 15;

    public static function put(string $runId, #[\SensitiveParameter] string $token): void
    {
        Cache::put(self::key($runId), Crypt::encryptString($token), now()->addMinutes(self::TtlMinutes));
    }

    public static function get(string $runId): ?string
    {
        $encrypted = Cache::get(self::key($runId));

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    public static function forget(string $runId): void
    {
        Cache::forget(self::key($runId));
    }

    public static function forgetUser(int $userId): void
    {
        // Tokens live at most TtlMinutes, so only runs created within that window can still hold one.
        $runs = ImportRun::query()->getQuery()->where('user_id', $userId)->where('created_at', '>=', now()->subMinutes(self::TtlMinutes + 1));
        foreach ($runs->pluck('id') as $runId) {
            if (is_string($runId)) {
                self::forget($runId);
            }
        }
    }

    private static function key(string $runId): string
    {
        return 'import-ohdear-token:'.$runId;
    }
}
