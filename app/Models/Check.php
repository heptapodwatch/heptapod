<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CheckFactory;
use Heptapod\Casts\CheckConfig;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\CheckPendingReason;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property CheckKey $type
 * @property array<string, mixed> $config
 * @property array{policy: int, relabeled_ids: list<string>}|null $policy_reclassification_pending
 * @property list<array{name: string, value: string}>|null $run_headers
 * @property array<string, mixed>|null $config_secrets
 * @property int $id
 * @property int $site_id
 * @property bool $enabled
 * @property int $interval_seconds
 * @property int $failure_threshold
 * @property bool $manual_run
 * @property string|null $claim_token
 * @property CarbonImmutable $next_run_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $run_started_at
 * @property CarbonImmutable|null $last_manual_requested_at
 * @property int|null $active_run_id
 * @property CheckResult $latest_run_result
 * @property int|null $latest_run_id
 * @property CarbonImmutable|null $latest_run_started_at
 * @property CarbonImmutable|null $latest_run_ended_at
 * @property CarbonImmutable|null $first_result_at
 * @property string|null $summary
 * @property array<string, int|float|string|bool|null>|null $summary_params
 * @property CarbonImmutable|null $awaiting_result_since
 * @property CarbonImmutable|null $last_loss_started_at Start of the latest lost attempt; NULL with lost_attempts > 0 means it never started.
 * @property int $lost_attempts
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $failing_since
 * @property CheckPendingReason|null $pending_reason Written only by Heptapod\Checks\CheckPending.
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id', 'config_secrets'])]
#[Hidden(['config_secrets', 'run_headers', 'manual_run'])]
final class Check extends Model
{
    use AuditsConfiguration;

    /** A new freshness episode: written when a claim ends, a check is enabled or its site resumes. */
    public const array FreshnessReset = ['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null];

    /** @return list<string> */
    protected function secretAttributes(): array
    {
        return ['config_secrets'];
    }

    protected function auditedAttributes(): array
    {
        return ['site_id', 'type', 'config', 'enabled', 'interval_seconds', 'failure_threshold'];
    }

    /** @use HasFactory<CheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CheckKey::class, 'enabled' => 'boolean', 'manual_run' => 'boolean', 'config' => CheckConfig::class,
            'config_secrets' => 'encrypted:array', 'run_headers' => 'encrypted:array', 'latest_run_result' => CheckResult::class,
            'summary_params' => 'array', 'interval_seconds' => 'integer', 'failure_threshold' => 'integer',
            'awaiting_result_since' => 'immutable_datetime', 'lost_attempts' => 'integer', 'last_loss_started_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer', 'next_run_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime',
            'run_started_at' => 'immutable_datetime', 'last_manual_requested_at' => 'immutable_datetime',
            'latest_run_started_at' => 'immutable_datetime',
            'latest_run_ended_at' => 'immutable_datetime', 'failing_since' => 'immutable_datetime',
            'first_result_at' => 'immutable_datetime',
            'policy_reclassification_pending' => 'array',
            'pending_reason' => CheckPendingReason::class,
        ];
    }

    /** Execution/authorized settings serializers only. Canonical schedule is projected elsewhere.
     * @return array<string, mixed>
     */
    public function executionConfig(): array
    {
        /** @var array<string, mixed> $public */
        $public = json_decode(json_encode($this->config, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return array_replace_recursive($public, $this->config_secrets ?? []);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<CheckRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    /** @return HasMany<CheckSnooze, $this> */
    public function snoozes(): HasMany
    {
        return $this->hasMany(CheckSnooze::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** Runs on schedule: enabled, its site not paused (a site marked for deletion is always paused) and its type available. */
    public function isActive(): bool
    {
        $site = $this->site ?? throw new \LogicException('An active check requires its site.');

        return $this->enabled && $site->paused_at === null && app(CheckRegistry::class)->availability($this->type)->reason === null;
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->getQuery()->where('checks.enabled', true)->whereNotIn('checks.type', app(CheckRegistry::class)->unavailableKeys())
            ->whereExists(static fn (QueryBuilder $site): QueryBuilder => $site->selectRaw('1')->from('sites')
                ->whereColumn('sites.id', 'checks.site_id')->whereNull('sites.paused_at'));
    }

    /**
     * Ends the current claim; every release path clears the same claim columns.
     *
     * @param  array<string, mixed>  $attributes  written in the same update
     */
    public function releaseClaim(bool $resetFreshness = true, array $attributes = []): void
    {
        $this->update(['claim_token' => null, 'dispatched_at' => null, 'lease_expires_at' => null, 'run_started_at' => null,
            'active_run_id' => null, 'run_headers' => null, 'manual_run' => false, ...($resetFreshness ? self::FreshnessReset : []), ...$attributes]);
    }

    protected static function booted(): void
    {
        self::creating(function (Check $check): void {
            $check->failure_threshold ??= $check->type === CheckKey::Uptime ? 2 : 1;
        });
    }
}
