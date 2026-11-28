<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A server's issues, open and resolved, newest opening first: the API's issue list pages and counts through this index
 * (ServerReader::issues). Built CONCURRENTLY outside a transaction, so issue writes go on while it builds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // A concurrent build that failed leaves an invalid index of the same name; drop it so a rerun builds it again.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS server_issues_history');
        DB::statement('CREATE INDEX CONCURRENTLY server_issues_history ON server_issues (server_id, opened_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS server_issues_history');
    }
};
