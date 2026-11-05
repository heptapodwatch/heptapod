<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Escape backslashes for PostgreSQL's text-to-bytea cast; raw URLs can exceed the B-tree entry limit.
        DB::statement("CREATE INDEX crawl_findings_snapshot_url_hash ON crawl_findings (snapshot_id, encode(sha256(replace(crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex'))");
        DB::statement('CREATE INDEX mixed_content_findings_snapshot ON mixed_content_findings (snapshot_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX mixed_content_findings_snapshot');
        DB::statement('DROP INDEX crawl_findings_snapshot_url_hash');
    }
};
