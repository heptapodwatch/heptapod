<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A server's issues, open and resolved, newest opening first: the API's issue list pages through this index. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX server_issues_history ON server_issues (server_id, opened_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX server_issues_history');
    }
};
