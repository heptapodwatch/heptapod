<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The existing site/time index lacks the event-ID tiebreak and sorted every retained audit head.
        DB::statement('CREATE INDEX events_deleted_activity_order ON events (occurred_at DESC, id DESC) WHERE site_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX events_deleted_activity_order');
    }
};
