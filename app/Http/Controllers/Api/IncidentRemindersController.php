<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\SnoozeIncidentReminders;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Incidents')]
final class IncidentRemindersController extends Controller
{
    use ResolvesApiPrincipal;

    public function store(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze): JsonResponse
    {
        $user = $this->principal($request);
        Validator::make($request->all(), ['minutes' => ['required', 'integer', 'between:1,525600']])->validate();
        $incident = $reader->scoped($user)->findOrFail($incident);

        return response()->json(['snoozed_until' => $snooze($incident, $request->integer('minutes'))->snoozed_until?->toIso8601String()]);
    }

    public function destroy(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze): JsonResponse
    {
        $user = $this->principal($request);
        $snooze($reader->scoped($user)->findOrFail($incident), null);

        return response()->json(['snoozed_until' => null]);
    }
}
