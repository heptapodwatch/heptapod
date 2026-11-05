<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE check_runs
            SET evidence = evidence - 'availability_failure'
            WHERE evidence -> 'availability_failure' = 'null'::jsonb
            SQL);
        DB::statement(<<<'SQL'
            UPDATE incidents
            SET evidence = evidence #- '{evidence,availability_failure}'
            WHERE evidence -> 'evidence' -> 'availability_failure' = 'null'::jsonb
            SQL);
    }

    public function down(): void
    {
        // Null availability metadata was not meaningful evidence and must not be restored.
    }
};
