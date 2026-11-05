<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Retry failed concurrent builds, which can leave an invalid index behind.
        $this->down();
        DB::statement('CREATE INDEX CONCURRENTLY crawl_snapshots_accepted_check_run ON crawl_snapshots (check_id, run_ended_at DESC, run_id DESC) WHERE run_ended_at IS NOT NULL');
        DB::statement('CREATE INDEX CONCURRENTLY crawl_findings_snapshot_id_id_index ON crawl_findings (snapshot_id, id)');
        DB::statement('CREATE INDEX CONCURRENTLY mixed_content_findings_snapshot_id_id_index ON mixed_content_findings (snapshot_id, id)');
        DB::statement("CREATE INDEX CONCURRENTLY mixed_content_findings_snapshot_identity ON mixed_content_findings (snapshot_id, (encode(sha256(replace(element_name || '|' || mixed_content_url || '|' || found_on_url, chr(92), chr(92) || chr(92))::bytea), 'hex')))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mixed_content_findings_snapshot_identity');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mixed_content_findings_snapshot_id_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_findings_snapshot_id_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_snapshots_accepted_check_run');
    }
};
