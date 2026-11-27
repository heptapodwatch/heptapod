<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One server issue from opening to resolution; at most one open per server, kind and scope (server_issues_one_open).
 * Kept after its server is removed (server_id null) as alert history.
 *
 * @property int $id
 * @property int|null $server_id
 * @property ServerIssueKind $kind
 * @property string $scope the mount for disk_full, else empty
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 * @property array<string, string|int|float> $opened_values the value, threshold and duration it opened on
 * @property array<string, string|int|float>|null $resolved_values the value it resolved on, or the reason it ended
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
#[WithoutTimestamps]
final class ServerIssue extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => ServerIssueKind::class, 'opened_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
            'opened_values' => 'array', 'resolved_values' => 'array'];
    }
}
