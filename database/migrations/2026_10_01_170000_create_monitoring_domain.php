<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 10)->default('en');
            $table->string('timezone')->default('UTC');
            $table->enum('client_scope', ['all', 'restricted'])->default('all');
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_email')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone')->default('UTC');
            $table->boolean('is_internal')->default(false);
            $table->timestampsTz(6);
        });
        DB::statement('CREATE UNIQUE INDEX clients_one_internal ON clients (is_internal) WHERE is_internal');
        DB::table('clients')->insert(['name' => 'Internal', 'slug' => 'internal', 'is_internal' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_internal_client() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_internal AND (TG_OP = 'DELETE' OR NOT NEW.is_internal) THEN
                    RAISE EXCEPTION 'The internal client cannot be removed';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER clients_protect_internal BEFORE DELETE OR UPDATE ON clients
            FOR EACH ROW EXECUTE FUNCTION protect_internal_client();
            SQL);
        Schema::create('client_user', function (Blueprint $table): void {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['client_id', 'user_id']);
        });
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->text('url')->unique();
            $table->string('friendly_name')->nullable();
            $table->string('group_name')->default('');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->text('crawler_headers')->nullable(); // encrypted:array; never generic props
            $table->jsonb('send_report_to_emails')->nullable();
            $table->jsonb('include_check_types_in_report')->nullable();
            $table->uuid('badge_id')->unique();
            $table->timestampTz('marked_for_deletion_at', 6)->nullable();
            $table->timestampTz('paused_at', 6)->nullable();
            $table->timestampsTz(6);
        });
        Schema::create('checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['uptime', 'performance', 'certificate_health', 'broken_links', 'mixed_content', 'dns', 'domain', 'lighthouse', 'cron', 'application_health', 'sitemap']);
            $table->jsonb('config')->default('{}');
            $table->text('config_secrets')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('failure_threshold'); // factory/configuration service: 2 uptime, 1 otherwise
            $table->timestampTz('next_run_at', 6)->useCurrent();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('dispatched_at', 6)->nullable();
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->timestampTz('run_started_at', 6)->nullable();
            $table->bigInteger('active_run_id')->nullable();
            $table->enum('latest_run_result', ['pending', 'succeeded', 'warning', 'failed', 'errored-or-timed-out'])->default('pending');
            $table->bigInteger('latest_run_id')->nullable();
            $table->timestampTz('latest_run_started_at', 6)->nullable();
            $table->timestampTz('latest_run_ended_at', 6)->nullable();
            $table->string('summary')->nullable();
            $table->jsonb('summary_params')->default('{}');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampsTz(6);
            $table->unique(['site_id', 'type']);
        });
        DB::statement('ALTER TABLE checks ADD CONSTRAINT checks_positive_schedule CHECK (interval_seconds > 0 AND failure_threshold > 0 AND consecutive_failures >= 0)');
        DB::statement('CREATE INDEX checks_due_enabled ON checks (next_run_at) WHERE enabled');
        // Run pointers intentionally have no FK: snapshots/evidence survive whole-partition retention.
        // active_run_id reserves a sequence ID before ended_at exists; latest_run_id pairs with latest_run_ended_at.
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE check_runs_id_seq AS bigint;
            CREATE TABLE check_runs (
                id bigint NOT NULL DEFAULT nextval('check_runs_id_seq'),
                check_id bigint NOT NULL REFERENCES checks(id) ON DELETE CASCADE,
                check_type varchar(64) NOT NULL CHECK (check_type IN ('uptime','performance','certificate_health','broken_links','mixed_content','dns','domain','lighthouse','cron','application_health','sitemap')),
                result varchar(32) NOT NULL CHECK (result IN ('succeeded','warning','failed','errored-or-timed-out')),
                started_at timestamptz(6), ended_at timestamptz(6) NOT NULL,
                summary varchar(255) NOT NULL, summary_params jsonb NOT NULL DEFAULT '{}',
                metrics jsonb DEFAULT '{}', evidence jsonb NOT NULL DEFAULT '{}', error text,
                PRIMARY KEY (id, ended_at), CHECK (started_at IS NULL OR ended_at >= started_at),
                CHECK (result = 'errored-or-timed-out' OR metrics IS NOT NULL)
            ) PARTITION BY RANGE (ended_at);
            ALTER SEQUENCE check_runs_id_seq OWNED BY check_runs.id;
            CREATE INDEX check_runs_check_ended ON check_runs (check_id, ended_at, id);
            SQL);
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        foreach ([-1, 0, 1] as $offset) {
            $start = $month->addMonths($offset);
            $end = $start->addMonth();
            $name = 'check_runs_'.$start->format('Y_m');
            DB::statement("CREATE TABLE {$name} PARTITION OF check_runs FOR VALUES FROM ('{$start->toIso8601String()}') TO ('{$end->toIso8601String()}')");
        }
        Schema::create('check_snoozes', function (Blueprint $table): void {
            $table->id();
            $table->enum('component_type', ['cron', 'application_health'])->nullable();
            $table->unsignedBigInteger('component_id')->nullable();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6);
            $table->string('snoozed_by');
            $table->string('unsnoozed_by')->nullable();
            $table->foreignId('snoozed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unsnoozed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('unsnoozed_at', 6)->nullable();
            $table->string('source');
            $table->timestampTz('created_at', 6)->useCurrent();
        });
        DB::statement('ALTER TABLE check_snoozes ADD CONSTRAINT snoozes_valid_window CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE check_snoozes ADD CONSTRAINT snoozes_valid_subject CHECK ((component_type IS NULL AND component_id IS NULL) OR (component_type IS NOT NULL AND component_id IS NOT NULL AND component_id > 0))');
        DB::statement('CREATE INDEX snoozes_whole_check ON check_snoozes (check_id, starts_at, ends_at) WHERE component_type IS NULL');
        DB::statement('CREATE INDEX snoozes_component ON check_snoozes (check_id, component_type, component_id, starts_at, ends_at) WHERE component_type IS NOT NULL');
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('cause', ['failed', 'errored']);
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('first_failure_at', 6);
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->timestampTz('acknowledged_at', 6)->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('evidence');
            $table->timestampsTz(6);
        });
        DB::statement('CREATE UNIQUE INDEX incidents_one_open ON incidents (check_id) WHERE resolved_at IS NULL');
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at', 6);
            $table->string('type');
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('from_result', ['pending', 'succeeded', 'warning', 'failed', 'errored-or-timed-out'])->nullable();
            $table->enum('to_result', ['pending', 'succeeded', 'warning', 'failed', 'errored-or-timed-out'])->nullable();
            $table->enum('cause', ['failed', 'errored'])->nullable();
            $table->unsignedInteger('payload_version')->default(1);
            $table->jsonb('payload');
            $table->string('dedupe_key')->nullable();
            $table->boolean('suppressed');
            $table->string('suppression_reason')->nullable();
            $table->timestampTz('suppression_evaluated_at', 6);
            $table->timestampTz('notification_routed_at', 6)->nullable();
            $table->unique(['check_id', 'dedupe_key']);
            $table->index(['site_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_suppression_reason CHECK (suppressed = (suppression_reason IS NOT NULL))');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_positive_version CHECK (payload_version > 0)');
        DB::statement('CREATE INDEX events_unrouted ON events (id) WHERE notification_routed_at IS NULL');
        foreach (['hourly', 'daily'] as $period) {
            Schema::create('check_run_rollups_'.$period, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('check_id')->constrained()->cascadeOnDelete();
                $table->timestampTz('bucket_start', 6);
                $table->unsignedBigInteger('succeeded_count')->default(0);
                $table->unsignedBigInteger('warning_count')->default(0);
                $table->unsignedBigInteger('failed_count')->default(0);
                $table->unsignedBigInteger('errored_count')->default(0);
                $table->jsonb('metric_sums')->default('{}');
                $table->jsonb('metric_counts')->default('{}');
                $table->jsonb('metric_p95')->default('{}');
                $table->timestampTz('computed_at', 6);
                $table->unique(['check_id', 'bucket_start']);
            });
        }
        Schema::create('dispatcher_heartbeats', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->timestampTz('last_seen_at', 6);
        });
    }

    public function down(): void
    {
        foreach (['dispatcher_heartbeats', 'check_run_rollups_daily', 'check_run_rollups_hourly', 'events', 'incidents', 'check_snoozes', 'check_runs', 'checks', 'sites', 'client_user', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_internal_client()');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'timezone', 'client_scope']));
    }
};
