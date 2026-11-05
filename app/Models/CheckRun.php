<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CheckRunFactory;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reads must use forRun(RunReference), never find(id).
 *
 * Check.latest_run_id is a retention-safe non-FK pointer, paired with latest_run_ended_at.
 *
 * @property int $id
 * @property int $check_id
 * @property CheckKey $check_type
 * @property RunResult $result
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable $ended_at
 * @property string $summary
 * @property array<string, int|float|string|bool|null>|null $summary_params
 * @property array<string, mixed>|null $metrics
 * @property array<string, mixed> $evidence
 * @property string|null $error
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Unguarded]
#[WithoutTimestamps]
final class CheckRun extends Model
{
    /** @use HasFactory<CheckRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['check_type' => CheckKey::class, 'result' => RunResult::class,
            'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
            'summary_params' => 'array', 'metrics' => 'array', 'evidence' => 'array'];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forRun(Builder $query, RunReference $run): Builder
    {
        // Query bindings otherwise format DateTime values to seconds and lose physical identity.
        return $query->where('id', $run->id)->where('ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'));
    }

    public function reference(): RunReference
    {
        return new RunReference($this->id, $this->ended_at);
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query): Builder
    {
        return $query->where('id', $this->getKeyForSelectQuery())->where('ended_at', $this->getRawOriginal('ended_at'));
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('id', $this->getKeyForSaveQuery())->where('ended_at', $this->getRawOriginal('ended_at'));
    }
}
