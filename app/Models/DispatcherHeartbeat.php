<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DispatcherHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $last_seen_at */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Unguarded]
#[WithoutIncrementing]
#[WithoutTimestamps]
final class DispatcherHeartbeat extends Model
{
    /** @use HasFactory<DispatcherHeartbeatFactory> */
    use HasFactory;

    #[\Override]
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }
}
