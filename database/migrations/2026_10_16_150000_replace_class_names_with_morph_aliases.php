<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rewrites stored PHP class names in every morph column to the aliases of Relation::enforceMorphMap.
 * The pairs are literal strings on purpose: they describe stored data, not the current classes.
 * Artisan migrate wraps this migration in one PostgreSQL transaction: an unknown class rolls back
 * all rewrites and leaves the migration pending. Batch logs describe attempted updates, not commits.
 * Calling up() directly has no such transaction and can leave partial progress; use Artisan migrate.
 * Re-running converges: existing aliases are untouched, including after an interrupted direct call.
 */
return new class extends Migration
{
    private const int BatchSize = 5000;

    /** @var array<string, string> */
    private const array Aliases = [
        'Lynkeus\Models\User' => 'user',
        'Lynkeus\Models\Site' => 'site',
        'Lynkeus\Models\Client' => 'client',
        'Lynkeus\Models\Check' => 'check',
        'Lynkeus\Models\CheckSnooze' => 'check_snooze',
        'Lynkeus\Models\Incident' => 'incident',
        'Lynkeus\Models\Invitation' => 'invitation',
        'Lynkeus\Models\MaintenancePeriod' => 'maintenance_period',
        'Lynkeus\Models\RecurringMaintenancePeriod' => 'recurring_maintenance_period',
        'Lynkeus\Models\MaintenanceSiteExclusion' => 'maintenance_site_exclusion',
        'Lynkeus\Models\NotificationDestination' => 'notification_destination',
    ];

    /** Morph columns outside activity_log; activity_log is rewritten in one id-ordered pass below. */
    private const array Columns = [
        'model_has_roles' => 'model_type',
        'model_has_permissions' => 'model_type',
        'personal_access_tokens' => 'tokenable_type',
        'media' => 'model_type',
        'taggables' => 'taggable_type',
    ];

    public function up(): void
    {
        foreach (self::Columns as $table => $column) {
            $this->rewriteColumn($table, $column);
        }
        $this->rewriteActivityLog();
        $this->failOnUnknownClassNames();
    }

    /** Forward-only: rollback is a backup restore. */
    public function down(): void {}

    private function rewriteColumn(string $table, string $column): void
    {
        $updated = 0;
        foreach (self::Aliases as $class => $alias) {
            // Some morph tables have no id column; ctid batches every table the same way.
            do {
                $count = DB::update("UPDATE {$table} SET {$column} = ? WHERE ctid = ANY(ARRAY(SELECT ctid FROM {$table} WHERE {$column} = ? LIMIT ".self::BatchSize.'))', [$alias, $class]);
                $updated += $count;
            } while ($count > 0);
        }
        Log::info('Morph aliases: rewrote class names.', ['column' => "{$table}.{$column}", 'rows' => $updated]);
    }

    private function rewriteActivityLog(): void
    {
        $cases = implode(' ', array_fill(0, count(self::Aliases), 'WHEN ? THEN ?'));
        $pairs = array_merge(...array_map(null, array_keys(self::Aliases), array_values(self::Aliases)));
        $classes = array_keys(self::Aliases);
        $in = implode(', ', array_fill(0, count($classes), '?'));
        $counts = ['subject_type' => 0, 'causer_type' => 0, 'properties.identity.type' => 0];
        $maxId = DB::table('activity_log')->max('id') ?? 0;
        if (! is_int($maxId)) {
            throw new UnexpectedValueException('activity_log.id must be an integer.');
        }
        for ($from = 0; $from <= $maxId; $from += self::BatchSize) {
            $range = [$from, $from + self::BatchSize];
            foreach (['subject_type', 'causer_type'] as $column) {
                $counts[$column] += DB::update("UPDATE activity_log SET {$column} = CASE {$column} {$cases} END WHERE id >= ? AND id < ? AND {$column} IN ({$in})", [...$pairs, ...$range, ...$classes]);
            }
            // Deletion audits keep the deleted model's morph class in properties.identity.type (AuditsConfiguration).
            $counts['properties.identity.type'] += DB::update("UPDATE activity_log SET properties = jsonb_set(properties::jsonb, '{identity,type}', to_jsonb(CASE properties::jsonb #>> '{identity,type}' {$cases} END))::json WHERE id >= ? AND id < ? AND properties::jsonb #>> '{identity,type}' IN ({$in})", [...$pairs, ...$range, ...$classes]);
        }
        foreach ($counts as $column => $rows) {
            Log::info('Morph aliases: rewrote class names.', ['column' => "activity_log.{$column}", 'rows' => $rows]);
        }
    }

    /** A class name without an alias would break enforceMorphMap at read time; stop the upgrade instead. */
    private function failOnUnknownClassNames(): void
    {
        $checks = [...array_map(static fn (string $table, string $column): string => "SELECT '{$table}.{$column}' AS col, {$column} AS type FROM {$table} WHERE {$column} LIKE 'Lynkeus%'", array_keys(self::Columns), self::Columns),
            "SELECT 'activity_log.subject_type', subject_type FROM activity_log WHERE subject_type LIKE 'Lynkeus%'",
            "SELECT 'activity_log.causer_type', causer_type FROM activity_log WHERE causer_type LIKE 'Lynkeus%'",
            "SELECT 'activity_log.properties.identity.type', properties::jsonb #>> '{identity,type}' FROM activity_log WHERE properties::jsonb #>> '{identity,type}' LIKE 'Lynkeus%'"];
        $left = DB::select('SELECT DISTINCT col, type FROM ('.implode(' UNION ALL ', $checks).') AS remaining');
        if ($left !== []) {
            throw new RuntimeException('Stored class names without a morph alias: '.json_encode($left, JSON_THROW_ON_ERROR));
        }
    }
};
