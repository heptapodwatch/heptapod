<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\RunPhase;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $check_id
 * @property string $claim_token
 * @property RunPhase $phase
 * @property string $queue
 * @property int|null $actor_user_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property int|null $progress_done
 * @property int|null $progress_total
 * @property string|null $phase_label
 * @property int|null $check_run_id
 * @property string|null $note
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[WithoutTimestamps]
final class CheckRunAttempt extends Model
{
    protected function casts(): array
    {
        return ['phase' => RunPhase::class, 'requested_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime',
            'progress_done' => 'integer', 'progress_total' => 'integer'];
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
