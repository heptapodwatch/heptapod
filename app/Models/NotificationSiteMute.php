<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A permanent, audited "not this site" for one channel. Maintenance and snooze stay the time-bound tools.
 * The table key is (destination_id, site_id).
 *
 * @property int $destination_id
 * @property int $site_id
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 */
#[Guarded([])]
#[WithoutTimestamps]
#[WithoutIncrementing]
final class NotificationSiteMute extends Model
{
    #[\Override]
    protected $primaryKey = 'destination_id';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
