<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Illuminate\Support\Facades\DB;

/** Deletes samples older than the retention window, per server through its (server_id, observed_at) index, in bounded chunks. */
final class PruneServerSamples
{
    private const int Chunk = 10000;

    public function __invoke(): int
    {
        $cutoff = now()->subDays(config()->integer('servers.retention_days'))->utc()->format('Y-m-d H:i:s.uP');
        $deleted = 0;
        foreach (DB::table('servers')->orderBy('id')->pluck('id') as $serverId) {
            do {
                $chunk = DB::delete('DELETE FROM server_samples WHERE id IN (SELECT id FROM server_samples WHERE server_id = ? AND observed_at < ?
                    ORDER BY observed_at LIMIT ?)', [$serverId, $cutoff, self::Chunk]);
                $deleted += $chunk;
            } while ($chunk === self::Chunk);
        }

        return $deleted;
    }
}
