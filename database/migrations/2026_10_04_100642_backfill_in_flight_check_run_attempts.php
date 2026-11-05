<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $registry = app(CheckRegistry::class);
        foreach (DB::table('checks')->whereNotNull('claim_token')->get(['id', 'claim_token', 'type', 'dispatched_at', 'run_started_at']) as $check) {
            if (! is_string($check->type)) {
                throw new LogicException('An active claim must have a check type.');
            }
            $type = CheckKey::from($check->type);
            $queue = in_array($type, $registry->keys(), true) ? $registry->get($type)->definition()->queue : 'checks-fast';
            DB::table('check_run_attempts')->insertOrIgnore([
                'check_id' => $check->id,
                'claim_token' => $check->claim_token,
                'phase' => $check->run_started_at === null ? 'queued' : 'running',
                'queue' => $queue,
                'requested_at' => $check->dispatched_at ?? now(),
                'due_at' => $check->dispatched_at ?? now(),
                'started_at' => $check->run_started_at,
                'note' => 'started_before_upgrade',
            ]);
        }
    }

    public function down(): void
    {
        // Upgrade attempts are history; do not delete them on rollback.
    }
};
