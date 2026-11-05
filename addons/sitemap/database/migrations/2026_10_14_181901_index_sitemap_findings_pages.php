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
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY sitemap_findings_snapshot_id_position_unique ON sitemap_findings (snapshot_id, position)');
        DB::statement('CREATE INDEX CONCURRENTLY sitemap_findings_snapshot_id_id_index ON sitemap_findings (snapshot_id, id)');
        DB::statement('CREATE INDEX CONCURRENTLY sitemap_findings_snapshot_id_status_id_index ON sitemap_findings (snapshot_id, status, id)');
        DB::statement('CREATE INDEX CONCURRENTLY sitemap_findings_snapshot_id_identity_hash_index ON sitemap_findings (snapshot_id, identity_hash)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sitemap_findings_snapshot_id_identity_hash_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sitemap_findings_snapshot_id_status_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sitemap_findings_snapshot_id_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sitemap_findings_snapshot_id_position_unique');
    }
};
