<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\ListFilters;
use Heptapod\Data\Ui\ActivityBatchData;
use Heptapod\Data\Ui\ActivityPageData;
use Heptapod\Enums\Capability;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Models\User;
use Heptapod\Queries\EventsReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\ScrollMetadata;

final class ActivityController extends Controller
{
    private const int BatchSize = 50;

    public function __invoke(#[CurrentUser] User $user, Request $request, EventsReader $reader): Response
    {
        abort_unless(Capability::ViewInstance->allows($user), 403);
        $reader = $reader->withServerIssues();
        $filters = ListFilters::read($request, [
            'q' => ['kind' => FilterKind::Search, 'label' => 'Filter by site or client', 'rules' => ['nullable', 'string', 'max:255']],
            'client' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['integer', 'min:1']],
            'check' => ['kind' => FilterKind::Select, 'label' => 'Check type', 'empty_label' => 'All checks', 'enum' => CheckKey::class, 'options' => ListFilters::checkOptions()],
            'period' => ['kind' => FilterKind::Select, 'label' => 'Time range', 'default' => '24h', 'options' => ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All']],
        ], ['before' => ['sometimes', 'integer', 'min:1'], 'group' => ['sometimes', 'integer', 'min:1']]);
        $period = $filters->value('period');
        $retentionDays = config()->integer('monitoring.raw_retention_days');
        if ($retentionDays < 1) {
            throw new \LogicException('Activity retention must be positive.');
        }
        $hours = match ($period) {
            '24h' => 24, '7d' => 168, '30d' => 720, 'all' => $retentionDays * 24,
            default => throw new \LogicException('Invalid activity period.')
        };
        $since = CarbonImmutable::now('UTC')->subHours(min($hours, $retentionDays * 24));
        $search = $filters->value('q');
        $client = $filters->integer('client');
        $check = $filters->value('check');
        $cursorId = $filters->parameter('before');
        $before = is_numeric($cursorId) ? (int) $cursorId : null;
        $loaded = null;
        $batch = static function (?int $before) use ($reader, $user, $since, $search, $client, $check): array {
            $cursor = $before === null ? null : $reader->activityCursor($user, $before, $since, $search, $client, $check);

            return $reader->activityBatch($user, $reader->activityGroups($user, $since, $search, $client, $check, cursor: $cursor, limit: self::BatchSize + 2), $cursor, self::BatchSize);
        };
        $expandedGroup = $filters->parameter('group');
        $groupId = is_numeric($expandedGroup) ? (int) $expandedGroup : null;
        $expanded = $groupId === null ? null : $reader->activityDetails($user, $groupId, $since, $search, $client, $check);

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
            filters: $filters->data(),
            expanded_id: $groupId,
            expanded_events: $expanded['events'] ?? [],
            expanded_events_pagination: $expanded['pagination'] ?? null,
        ));
    }
}
