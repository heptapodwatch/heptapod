<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\ActivityBatchData;
use Heptapod\Data\Ui\ActivityFilterData;
use Heptapod\Data\Ui\ActivityPageData;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Models\User;
use Heptapod\Queries\EventsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\ScrollMetadata;

final class ActivityController extends Controller
{
    private const int BatchSize = 50;

    public function __invoke(Request $request, EventsReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client' => ['sometimes', 'integer', 'min:1'],
            'check' => ['sometimes', Rule::enum(CheckKey::class)],
            'period' => ['sometimes', Rule::in(['24h', '7d', '30d', 'all'])],
            'before' => ['sometimes', 'integer', 'min:1'],
            'group' => ['sometimes', 'integer', 'min:1'],
        ])->validate();
        $period = $request->string('period', '24h')->toString();
        $retentionDays = config()->integer('monitoring.raw_retention_days');
        if ($retentionDays < 1) {
            throw new \LogicException('Activity retention must be positive.');
        }
        $hours = match ($period) {
            '24h' => 24, '7d' => 168, '30d' => 720, 'all' => $retentionDays * 24,
            default => throw new \LogicException('Invalid activity period.')
        };
        $since = CarbonImmutable::now('UTC')->subHours(min($hours, $retentionDays * 24));
        $search = $request->filled('q') ? $request->string('q')->toString() : null;
        $client = $request->filled('client') ? $request->integer('client') : null;
        $check = $request->filled('check') ? CheckKey::from($request->string('check')->toString()) : null;
        $before = $request->filled('before') ? $request->integer('before') : null;
        $loaded = null;
        $batch = static function (?int $before) use ($reader, $user, $since, $search, $client, $check): array {
            $cursor = $before === null ? null : $reader->activityCursor($user, $before, $since, $search, $client, $check?->value);

            return $reader->activityBatch($reader->activityGroups($user, $since, $search, $client, $check?->value, cursor: $cursor, limit: self::BatchSize + 2), $cursor, self::BatchSize);
        };
        $groupId = $request->filled('group') ? $request->integer('group') : null;
        $expanded = $groupId === null ? null : $reader->activityDetails($user, $groupId, $since, $search, $client, $check?->value);

        if ($expanded !== null && $expanded['events'] === []) {
            throw new ExpectedRefusal(
                status: 404,
                messageKey: 'errors.activity.group_pruned',
                errorCode: 'activity_group_pruned',
                tone: 'info',
            );
        }

        return Inertia::render('activity/index', new ActivityPageData(
            // Older batches load by infinite scroll (`before` = the last group's latest event); the URL keeps no cursor.
            events: Inertia::scroll(static function () use (&$loaded, $batch, $before): ActivityBatchData {
                return new ActivityBatchData(($loaded ??= $batch($before))['groups']);
            }, metadata: static function () use (&$loaded, $batch, $before): ScrollMetadata {
                return new ScrollMetadata('before', null, ($loaded ??= $batch($before))['older'], $before);
            }),
            // Live reloads ask for the newest batch only; the loaded older batches stay as they are.
            head: Inertia::optional(static fn (): ActivityBatchData => new ActivityBatchData($batch(null)['groups'])),
            retention_days: $retentionDays,
            clients: $sites->clients($user),
            filters: new ActivityFilterData($search ?? '', $client, $check, $period),
            expanded_id: $groupId,
            expanded_events: $expanded['events'] ?? [],
            expanded_pagination: $expanded['pagination'] ?? null,
        ));
    }
}
