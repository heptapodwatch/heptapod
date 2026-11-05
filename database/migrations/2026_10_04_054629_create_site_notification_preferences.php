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
        Schema::create('site_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('mode')->default('inherit');
            $table->jsonb('weekdays')->nullable();
            $table->string('window_start', 5)->nullable();
            $table->string('window_end', 5)->nullable();
        });
        DB::statement("ALTER TABLE site_notification_preferences ADD CONSTRAINT site_notification_mode CHECK (mode IN ('inherit', 'business_hours'))");
        Schema::create('site_notification_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['site_id', 'started_at']);
        });
        Schema::create('site_notification_recipients', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('notification_destinations')->cascadeOnDelete();
            $table->primary(['site_id', 'destination_id']);
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->timestampTz('digest_slot_at')->nullable();
            $table->date('last_digest_local_date')->nullable();
            $table->dropUnique(['destination_id', 'digest_local_date']);
            $table->unique(['destination_id', 'digest_slot_at']);
        });
        Schema::create('site_notification_follows', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique(['destination_id', 'digest_slot_at']);
            $table->dropColumn(['digest_slot_at', 'last_digest_local_date']);
            $table->unique(['destination_id', 'digest_local_date']);
        });
        Schema::dropIfExists('site_notification_follows');
        Schema::dropIfExists('site_notification_recipients');
        Schema::dropIfExists('site_notification_mutes');
        Schema::dropIfExists('site_notification_preferences');
    }
};
