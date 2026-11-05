<?php

declare(strict_types=1);

namespace Heptapod\Addons\Cron;

use Illuminate\Support\Facades\DB;

final class CronDefinitionRevision
{
    public function current(int $checkId): int
    {
        // A parent without definition writes (including pre-migration parents) starts at revision zero.
        /** @var int|null $revision */
        $revision = DB::table('cron_definition_revisions')->where('check_id', $checkId)->value('revision');

        return $revision ?? 0;
    }

    /** Writers call inside their transaction, before taking any task locks. */
    public function lock(int $checkId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Cron definition revisions require a write transaction.');
        }
        DB::table('checks')->where('id', $checkId)->lockForUpdate()->firstOrFail();
        DB::table('cron_definition_revisions')->insertOrIgnore(['check_id' => $checkId, 'revision' => 0]);
    }

    public function advance(int $checkId): void
    {
        $this->lock($checkId);
        DB::table('cron_definition_revisions')->where('check_id', $checkId)->increment('revision');
    }

    public function requiresSerialization(int $checkId): bool
    {
        return DB::table('cron_definition_revisions')->where('check_id', $checkId)->where('serialized_retry', true)->exists();
    }

    public function serializeNext(int $checkId, bool $required): void
    {
        $this->lock($checkId);
        DB::table('cron_definition_revisions')->where('check_id', $checkId)->update(['serialized_retry' => $required]);
    }
}
