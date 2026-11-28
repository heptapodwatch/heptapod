<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SaveIncidentNote;
use Heptapod\Actions\SnoozeIncidentReminders;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class IncidentActionsController extends Controller
{
    public function update(#[CurrentUser] User $user, Request $request, int $incident, IncidentsReader $reader, SaveIncidentNote $save, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $data = Validator::make($request->all(), ['note' => ['present', 'nullable', 'string', 'max:10000']])->validate();
        $note = $data['note'];
        if ($note !== null && ! is_string($note)) {
            throw new \LogicException('Validated incident note must be text.');
        }
        $authorize->incident($user, $reader->scoped($user)->findOrFail($incident), static fn (Incident $visible): Incident => $save($visible, $user, $note));

        return back();
    }

    public function store(#[CurrentUser] User $user, Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        Validator::make($request->all(), ['minutes' => ['required', 'integer', 'between:1,525600']])->validate();
        $authorize->incident($user, $reader->scoped($user)->findOrFail($incident), static fn (Incident $visible): Incident => $snooze($visible, $request->integer('minutes')));

        return back();
    }

    public function destroy(#[CurrentUser] User $user, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $authorize->incident($user, $reader->scoped($user)->findOrFail($incident), static fn (Incident $visible): Incident => $snooze($visible, null));

        return back();
    }
}
