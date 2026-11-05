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
        // One row per opened system issue; resolved rows stay as history. Rows are few (one per kind per outage).
        Schema::create('system_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40);
            $table->jsonb('details');
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('resolved_at', 6)->nullable();
        });
        // Mail state per recipient and phase, so a retry reaches only the recipients still pending.
        Schema::create('system_issue_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 10);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            // A recipient who lost eligibility (opt-out, role change, disabled) before submission: kept, never sent.
            $table->timestampTz('suppressed_at', 6)->nullable();
            $table->string('suppression_reason', 40)->nullable();
            $table->unique(['system_issue_id', 'user_id', 'phase']);
        });
        DB::statement("ALTER TABLE system_issue_deliveries ADD CONSTRAINT system_issue_deliveries_phase CHECK (phase IN ('open', 'recovery'))");
        DB::statement('CREATE INDEX system_issue_deliveries_pending ON system_issue_deliveries (id) WHERE delivered_at IS NULL AND suppressed_at IS NULL');
        // The dedupe rule: at most one open issue per kind, enforced by the database.
        DB::statement('CREATE UNIQUE INDEX system_issues_one_open_per_kind ON system_issues (kind) WHERE resolved_at IS NULL');
        DB::statement('CREATE INDEX system_issues_resolved_at ON system_issues (resolved_at) WHERE resolved_at IS NOT NULL');
        // Owners always get system issue mails; Admins only after opting in.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('system_issue_mails')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('system_issue_mails');
        });
        Schema::dropIfExists('system_issue_deliveries');
        Schema::dropIfExists('system_issues');
    }
};
