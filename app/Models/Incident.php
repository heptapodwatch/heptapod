<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Heptapod\Enums\IncidentCause;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $opened_during_maintenance_window_id
 * @property CarbonImmutable|null $alert_episode_started_at
 * @property int|null $alert_after_minutes
 * @property int|null $alert_recovery_checks
 * @property IncidentCause $cause
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $first_failure_at
 * @property CarbonImmutable|null $routing_reconcile_requested_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $note_updated_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    public function requiresCompleteRecovery(): bool
    {
        $evidence = $this->evidence;
        if (! array_key_exists('requires_complete_recovery', $evidence)) {
            return $this->cause === IncidentCause::Failed;
        }
        if (! is_bool($evidence['requires_complete_recovery'])) {
            throw new \LogicException('Incident recovery evidence must be boolean.');
        }

        return $evidence['requires_complete_recovery'];
    }

    protected function casts(): array
    {
        return ['routing_reconcile_requested_at' => 'immutable_datetime', 'alert_episode_started_at' => 'immutable_datetime', 'alert_after_minutes' => 'integer', 'alert_recovery_checks' => 'integer', 'opened_during_maintenance_window_id' => 'integer', 'cause' => IncidentCause::class, 'evidence' => 'array', 'opened_at' => 'immutable_datetime', 'first_failure_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'snoozed_until' => 'immutable_datetime', 'note_updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return BelongsTo<User, $this> */
    public function noteAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'note_author_user_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
