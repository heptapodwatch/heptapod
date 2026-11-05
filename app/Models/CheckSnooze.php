<?php

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CheckSnoozeFactory;
use Heptapod\Concerns\AuditsConfiguration;
use Heptapod\Contracts\SuppressionComponent;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $unsnoozed_at
 * @property CarbonImmutable|null $created_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class CheckSnooze extends Model
{
    use AuditsConfiguration;

    protected function auditedAttributes(): array
    {
        return ['check_id', 'component_type', 'component_id', 'starts_at', 'ends_at', 'snoozed_by', 'unsnoozed_by', 'unsnoozed_at', 'source'];
    }

    /** @use HasFactory<CheckSnoozeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['component_type' => SuppressionComponent::class, 'component_id' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'unsnoozed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }
}
