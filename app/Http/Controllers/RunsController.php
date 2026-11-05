<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunPhase;
use Heptapod\Data\Ui\RunsBoardData;
use Heptapod\Data\Ui\RunsFilterData;
use Heptapod\Data\Ui\RunsHistoryPageData;
use Heptapod\Data\Ui\RunsIndexPageData;
use Heptapod\Models\User;
use Heptapod\Queries\RunsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class RunsController extends Controller
{
    public function __invoke(Request $request, RunsReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), [
            'client' => ['sometimes', 'integer', 'min:1'],
            'check' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(CheckKey::class)],
            'state' => ['sometimes', Rule::enum(RunPhase::class)],
            'before' => ['sometimes', 'integer', 'min:1'],
            'show_same_result' => ['sometimes', 'boolean'],
            'period' => ['sometimes', Rule::in(['24h', '7d'])],
        ])->validate();
        $period = $request->string('period', '24h')->toString();
        $since = CarbonImmutable::now('UTC')->subHours(match ($period) {
            '24h' => 24, '7d' => 168, default => throw new \LogicException('Invalid runs period.')
        });
        $filters = new RunsFilterData(
            $request->filled('client') ? $request->integer('client') : null,
            $request->filled('check') ? $request->integer('check') : null,
            $request->filled('type') ? CheckKey::from($request->string('type')->toString()) : null,
            $request->filled('state') ? RunPhase::from($request->string('state')->toString()) : null,
            $request->filled('before') ? $request->integer('before') : null,
            $request->boolean('show_same_result'),
            $period,
        );

        $board = null;
        $history = null;
        $read = function () use (&$board, $reader, $user, $filters): RunsBoardData {
            return $board ??= $reader->board($user, $filters);
        };
        $readHistory = function () use (&$history, $reader, $user, $filters, $since): RunsHistoryPageData {
            return $history ??= $reader->history($user, $filters->check, $filters->before, $filters->client, $filters->type?->value, $filters->state, $filters->show_same_result, since: $since);
        };

        return Inertia::render('runs/index', new RunsIndexPageData(
            current: Lazy::closure(static fn (): array => $read()->current),
            queued_more: Lazy::closure(static fn (): int => $read()->queued_more),
            finished: Lazy::closure(static fn (): array => $read()->finished),
            runs: Lazy::closure(static fn (): array => $readHistory()->runs),
            next_cursor: Lazy::closure(static fn (): ?string => $readHistory()->next_cursor),
            clients: Lazy::closure(static fn (): array => $sites->clients($user)),
            filters: $filters,
        ));
    }
}
