<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\ActivityFilterData;
use Heptapod\Data\Ui\ActivityPageData;
use Heptapod\Models\User;
use Heptapod\Queries\EventsReader;
use Heptapod\Queries\SitesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    public function __invoke(Request $request, EventsReader $reader, SitesReader $sites): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client' => ['sometimes', 'integer', 'min:1'],
            'check' => ['sometimes', Rule::enum(CheckKey::class)],
            'period' => ['sometimes', Rule::in(['24h', '7d', '30d', 'all'])],
            'before' => ['sometimes', 'integer', 'min:1', 'prohibits:after'],
            'after' => ['sometimes', 'integer', 'min:1', 'prohibits:before'],
            'group' => ['sometimes', 'integer', 'min:1'],
            'event_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
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
        $after = $request->filled('after') ? $request->integer('after') : null;
        $cursorId = $before ?? $after;
        $cursor = $cursorId === null ? null : $reader->activityCursor($user, $cursorId, $since, $search, $client, $check?->value);
        $groups = $reader->activityGroups($user, $since, $search, $client, $check?->value, cursor: $cursor, newer: $after !== null, limit: 21);
        $feed = $reader->readActivity($groups, $cursor, $after !== null);
        $groupId = $request->filled('group') ? $request->integer('group') : null;
        $eventPage = $request->integer('event_page', 1);
        $expanded = $groupId === null ? [] : $reader->activityDetails($user, $groupId, $eventPage, $since, $search, $client, $check?->value);

        abort_if($groupId !== null && $expanded === [], 404);

        return Inertia::render('activity/index', new ActivityPageData($feed['events'], $feed['older'], $feed['newer'], $retentionDays,
            $sites->clients($user), new ActivityFilterData($search ?? '', $client, $check, $period, $before, $after), $groupId, $expanded, $eventPage)->toArray());
    }
}
