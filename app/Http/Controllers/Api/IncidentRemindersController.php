<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SnoozeIncidentReminders;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Incident;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

#[Group('Incidents')]
final class IncidentRemindersController extends Controller
{
    use ResolvesApiPrincipal;

    public function store(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $user = $this->principal($request);
        Validator::make($request->all(), ['minutes' => ['required', 'integer', 'between:1,525600']])->validate();
        $snoozed = $authorize->incident($user, $reader->scoped($user)->findOrFail($incident), static fn (Incident $visible): Incident => $snooze($visible, $request->integer('minutes')));

        return response()->json(['snoozed_until' => $this->snoozedUntil($snoozed)]);
    }

    /** Typed here: the OpenAPI export cannot infer the incident through the locked write. */
    private function snoozedUntil(Incident $incident): ?string
    {
        return $incident->snoozed_until?->toIso8601String();
    }

    public function destroy(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): JsonResponse
    {
        $user = $this->principal($request);
        $authorize->incident($user, $reader->scoped($user)->findOrFail($incident), static fn (Incident $visible): Incident => $snooze($visible, null));

        return response()->json(['snoozed_until' => null]);
    }
}
