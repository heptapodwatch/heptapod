<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the retained-check branch out of the much larger live-check event range.
        DB::statement('CREATE INDEX events_deleted_check_activity_order ON events (occurred_at DESC, id DESC) WHERE site_id IS NOT NULL AND check_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX events_deleted_check_activity_order');
    }
};
