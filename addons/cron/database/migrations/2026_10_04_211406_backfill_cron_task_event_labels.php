<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE events
            SET payload = jsonb_set(
                jsonb_set(payload, '{summary}', to_jsonb(
                    CASE payload->>'result'
                        WHEN 'succeeded' THEN 'cron.task_succeeded'
                        WHEN 'failed' THEN 'cron.task_failed'
                        WHEN 'warning' THEN 'cron.task_late'
                    END
                )),
                '{summary_params}', '{"presentation":"direct"}'::jsonb
            )
            WHERE type = 'cron.task_result_changed'
              AND payload->>'result' IN ('succeeded', 'failed', 'warning')
              AND payload->>'summary' IS NULL
            SQL);
    }

    public function down(): void
    {
        // Retained event presentation is not reverted once published.
    }
};
