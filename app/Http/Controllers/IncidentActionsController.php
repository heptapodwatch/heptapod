<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\SaveIncidentNote;
use Heptapod\Actions\SnoozeIncidentReminders;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class IncidentActionsController extends Controller
{
    public function update(Request $request, int $incident, SaveIncidentNote $save, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = Validator::make($request->all(), ['note' => ['present', 'nullable', 'string', 'max:10000']])->validate();
        $note = $data['note'];
        if ($note !== null && ! is_string($note)) {
            throw new \LogicException('Validated incident note must be text.');
        }
        $authorize->incident($user, $incident, static fn (Incident $visible): Incident => $save($visible, $user, $note));

        return back();
    }

    public function store(Request $request, int $incident, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['minutes' => ['required', 'integer', 'between:1,525600']])->validate();
        $authorize->incident($user, $incident, static fn (Incident $visible): Incident => $snooze($visible, $request->integer('minutes')));

        return back();
    }

    public function destroy(Request $request, int $incident, SnoozeIncidentReminders $snooze, AuthorizeSiteWrite $authorize): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $authorize->incident($user, $incident, static fn (Incident $visible): Incident => $snooze($visible, null));

        return back();
    }
}
