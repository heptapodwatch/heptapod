<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\SaveIncidentNote;
use Heptapod\Actions\SnoozeIncidentReminders;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class IncidentActionsController extends Controller
{
    public function update(Request $request, int $incident, IncidentsReader $reader, SaveIncidentNote $save): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = Validator::make($request->all(), ['note' => ['present', 'nullable', 'string', 'max:10000']])->validate();
        $note = $data['note'];
        if ($note !== null && ! is_string($note)) {
            throw new \LogicException('Validated incident note must be text.');
        }
        $save($reader->scoped($user)->findOrFail($incident), $user, $note);

        return back();
    }

    public function store(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['minutes' => ['required', 'integer', 'between:1,525600']])->validate();
        $snooze($reader->scoped($user)->findOrFail($incident), $request->integer('minutes'));

        return back();
    }

    public function destroy(Request $request, int $incident, IncidentsReader $reader, SnoozeIncidentReminders $snooze): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snooze($reader->scoped($user)->findOrFail($incident), null);

        return back();
    }
}
