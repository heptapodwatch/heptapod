<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE checks
            SET config = (config - 'exclude_patterns') || jsonb_build_object('do_not_crawl_urls', (
                SELECT coalesce(jsonb_agg(pattern ORDER BY first_position), '[]'::jsonb)
                FROM (
                    SELECT pattern, min(position) AS first_position
                    FROM jsonb_array_elements(
                        coalesce(config->'do_not_crawl_urls', '[]'::jsonb) || (config->'exclude_patterns')
                    ) WITH ORDINALITY AS patterns(pattern, position)
                    GROUP BY pattern
                ) AS distinct_patterns
            ))
            WHERE type = 'broken_links' AND config->'exclude_patterns' IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        // The lists served the same purpose; preserve the entries, not their unrecoverable original split.
    }
};
