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
        Schema::table('checks', function (Blueprint $table): void {
            $table->timestampTz('last_manual_requested_at', 6)->nullable();
        });
        Schema::table('check_run_attempts', function (Blueprint $table): void {
            $table->string('origin', 16)->default('scheduled');
            $table->foreignId('actor_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
        });
        // Historical attempts with an actor came from a person; internal reruns before this column count as scheduled.
        DB::statement("UPDATE check_run_attempts SET origin = 'manual' WHERE actor_user_id IS NOT NULL");
        DB::statement(<<<'SQL'
            CREATE INDEX check_run_attempts_manual_active ON check_run_attempts (queue)
                WHERE origin = 'manual' AND phase IN ('queued', 'running')
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS check_run_attempts_manual_active');
        Schema::table('check_run_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_token_id');
            $table->dropColumn('origin');
        });
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('last_manual_requested_at');
        });
    }
};
