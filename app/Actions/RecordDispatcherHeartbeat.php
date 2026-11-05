<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\DatabaseClock;
use Illuminate\Support\Facades\DB;

final readonly class RecordDispatcherHeartbeat
{
    public function __construct(private DatabaseClock $clock) {}

    public function __invoke(string $id): void
    {
        DB::table('dispatcher_heartbeats')->upsert([
            'id' => $id, 'last_seen_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
        ], ['id'], ['last_seen_at']);
    }
}
