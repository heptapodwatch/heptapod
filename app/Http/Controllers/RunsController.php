<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\FilterKind;
use Heptapod\Contracts\FilterSlot;
use Heptapod\Contracts\ListFilters;
use Heptapod\Contracts\RunPhase;
use Heptapod\Data\Ui\RunsBoardData;
use Heptapod\Data\Ui\RunsFilterData;
use Heptapod\Data\Ui\RunsHistoryData;
use Heptapod\Data\Ui\RunsIndexPageData;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class RunsController extends Controller
{
    public function __invoke(Request $request, RunsReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $query = ListFilters::read($request, [
            'check' => ['kind' => FilterKind::Chip, 'label' => 'Check', 'rules' => ['integer', 'min:1'],
                'display' => static function (string $id, array $remaining) use ($reader, $user): array {
                    $check = $reader->findVisibleCheck($user, (int) $id);
                    $site = $check?->site;
                    unset($remaining['before']);
                    $clearUrl = route('runs.index', $remaining, absolute: false);
                    if (! $check instanceof Check || ! $site instanceof Site) {
                        return ['display' => __('runs.filters.checkUnavailable'), 'clear_url' => $clearUrl];
                    }

                    return [
                        'display' => __("checks.{$check->type->value}.label").' · '.$site->displayName(),
                        'clear_url' => $clearUrl,
                        'detail_url' => route('sites.checks.show', ['site' => $check->site_id, 'check' => $check->id], absolute: false).'#check-history',
                    ];
                }],
            'client' => ['kind' => FilterKind::Combobox, 'label' => 'Client', 'empty_label' => 'All clients', 'source' => 'clients', 'rules' => ['integer', 'min:1'], 'disabled_by' => 'check'],
            'type' => ['kind' => FilterKind::Select, 'label' => 'Check type', 'empty_label' => 'All checks', 'enum' => CheckKey::class, 'disabled_by' => 'check',
                'options' => ListFilters::checkOptions()],
            'state' => ['kind' => FilterKind::Select, 'label' => 'State', 'empty_label' => 'runs.screen.allStates', 'enum' => RunPhase::class,
                'options' => ['scheduled' => 'Scheduled', 'queued' => 'Queued', 'running' => 'Running', 'late' => 'Late', 'done' => 'Done', 'failed' => 'Failed', 'skipped' => 'Skipped', 'cancelled' => 'Cancelled']],
            'show_same_result' => ['kind' => FilterKind::Boolean, 'label' => 'Show runs with the same result as before', 'rules' => ['boolean'], 'slot' => FilterSlot::History, 'widens' => true],
            'period' => ['kind' => FilterKind::Segmented, 'label' => 'runs.board.historyRange', 'default' => '24h', 'options' => ['24h' => 'Last 24 hours', '7d' => 'Last 7 days'], 'slot' => FilterSlot::HistoryRange],
            'started' => ['kind' => FilterKind::Select, 'label' => 'run.started', 'empty_label' => 'run.started.all', 'options' => ['manual' => 'run.started.manual']],
        ], ['before' => ['sometimes', 'integer', 'min:1']]);
        $period = $query->value('period') ?? throw new \LogicException('Missing runs period.');
        $since = CarbonImmutable::now('UTC')->subHours(match ($period) {
            '24h' => 24, '7d' => 168, default => throw new \LogicException('Invalid runs period.')
        });
        $type = $query->value('type');
        $state = $query->value('state');
        $before = $query->parameter('before');
        $filters = new RunsFilterData(
            $query->integer('client'),
            $query->integer('check'),
            $type === null ? null : CheckKey::from($type),
            $state === null ? null : RunPhase::from($state),
            is_numeric($before) ? (int) $before : null,
            $query->value('show_same_result') === '1',
            $period,
            ListPageSize::resolve($request, 'runs'),
            $query->value('started') === 'manual',
        );

        $board = null;
        $history = null;
        $read = function () use (&$board, $reader, $user, $filters): RunsBoardData {
            return $board ??= $reader->board($user, $filters);
        };
        $readHistory = function () use (&$history, $reader, $user, $filters, $since): RunsHistoryData {
            return $history ??= $reader->history($user, $filters->per_page, $filters->check, $filters->before, $filters->client, $filters->type?->value, $filters->state, $filters->show_same_result, since: $since, manualOnly: $filters->started_manually);
        };

        return Inertia::render('runs/index', new RunsIndexPageData(
            current: Lazy::closure(static fn (): array => $read()->current),
            queued_more: Lazy::closure(static fn (): int => $read()->queued_more),
            finished: Lazy::closure(static fn (): array => $read()->finished),
            runs: Lazy::closure(static fn (): array => $readHistory()->runs),
            next_cursor: Lazy::closure(static fn (): ?string => $readHistory()->next_cursor),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            filters: Lazy::closure(static fn (): array => $query->data()),
            before: $filters->before,
            per_page: $filters->per_page,
            attempt_retention_days: config()->integer('monitoring.attempt_retention_days'),
        ));
    }
}
