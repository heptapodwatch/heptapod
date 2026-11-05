<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Carbon\CarbonImmutable;
use Heptapod\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One import from an external account, from the plan to the last attempt. Holds no secret: the source token
 * lives only in Support\ImportTokenStore, keyed by this id.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $source
 * @property ImportRunStatus $status
 * @property int $expected
 * @property int $attempts
 * @property list<array{id: int, name: string}> $teams
 * @property list<array<string, mixed>> $plan Sanitized plan rows (no details text).
 * @property array<string, array{client_id: int|null, new_client: string|null}>|null $assignments Resolved target per new site, keyed by source id.
 * @property array<string, array<string, mixed>> $results Latest result row per source id, kept across attempts.
 * @property array<string, int> $created_client_ids New client name → client id created by this run.
 * @property string|null $remote_error
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
#[DateFormat('Y-m-d H:i:s.uP')]
#[Guarded(['id'])]
final class ImportRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['status' => ImportRunStatus::class, 'expected' => 'integer', 'attempts' => 'integer', 'teams' => 'array', 'plan' => 'array', 'assignments' => 'array',
            'results' => 'array', 'created_client_ids' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
