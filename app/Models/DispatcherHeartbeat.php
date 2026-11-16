<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DispatcherHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @property CarbonImmutable $last_seen_at */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Unguarded]
#[WithoutIncrementing]
#[WithoutTimestamps]
final class DispatcherHeartbeat extends Model
{
    /** @use HasFactory<DispatcherHeartbeatFactory> */
    use HasFactory;

    use MassPrunable;

    #[\Override]
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    /**
     * Each dispatcher start adds a row (hostname:pid). The newest row stays even when old: the dispatcher measures its
     * downtime from it, and System health shows its age.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $query = self::query();
        $query->getQuery()->where('last_seen_at', '<', now()->subDay())
            ->whereNotIn('id', DB::table('dispatcher_heartbeats')->select('id')->orderByDesc('last_seen_at')->limit(1));

        return $query;
    }
}
