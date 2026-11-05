<?php

namespace Heptapod\Models;

use Database\Factories\CheckRunRollupHourlyFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[Table(name: 'check_run_rollups_hourly')]
#[WithoutTimestamps]
final class CheckRunRollupHourly extends Model
{
    /** @use HasFactory<CheckRunRollupHourlyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['bucket_start' => 'immutable_datetime', 'computed_at' => 'immutable_datetime',
            'succeeded_count' => 'integer', 'warning_count' => 'integer', 'failed_count' => 'integer', 'errored_count' => 'integer',
            'metric_sums' => 'array', 'metric_counts' => 'array', 'metric_p95' => 'array'];
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }
}
