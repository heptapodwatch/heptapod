<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->enum('component_type', ['cron', 'application_health'])->nullable();
            $table->unsignedBigInteger('component_id')->nullable();
            $table->index(['check_id', 'component_type', 'component_id', 'occurred_at'], 'events_component_subject');
        });
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_valid_subject CHECK ((component_type IS NULL AND component_id IS NULL) OR (check_id IS NOT NULL AND component_type IS NOT NULL AND component_id IS NOT NULL AND component_id > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_valid_subject');
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_component_subject');
            $table->dropColumn(['component_type', 'component_id']);
        });
    }
};
