<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX incidents_opened_order ON incidents (opened_at DESC, id DESC)');
        DB::statement('CREATE INDEX incidents_site_opened_order ON incidents (site_id, opened_at DESC, id DESC)');
        DB::statement('CREATE INDEX incidents_check_opened_order ON incidents (check_id, opened_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX incidents_check_opened_order');
        DB::statement('DROP INDEX incidents_site_opened_order');
        DB::statement('DROP INDEX incidents_opened_order');
    }
};
