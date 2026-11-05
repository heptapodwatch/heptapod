<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Heptapod\Enums\HealthStatus;
use Heptapod\SystemHealth\SystemIssueKind;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An unhealthy condition of the instance itself, from opening to recovery. Not a site incident.
 *
 * @property int $id
 * @property SystemIssueKind $kind
 * @property string $scope
 * @property array<string, int|string|null> $details latest facts of the issue
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Unguarded]
#[WithoutTimestamps]
final class SystemIssue extends Model
{
    /** @return Builder<self> open issues, oldest first; at most one per kind and scope */
    public static function open(): Builder
    {
        $query = self::query();
        $query->getQuery()->whereNull('resolved_at')->orderBy('opened_at')->orderBy('id');

        return $query;
    }

    public function severity(): HealthStatus
    {
        return $this->kind === SystemIssueKind::DiskSpaceLow
            ? (in_array($this->details['severity'], ['critical', 'unreadable'], true) ? HealthStatus::Down : HealthStatus::Warn)
            : $this->kind->severity();
    }

    protected function casts(): array
    {
        return ['kind' => SystemIssueKind::class, 'details' => 'array', 'opened_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
