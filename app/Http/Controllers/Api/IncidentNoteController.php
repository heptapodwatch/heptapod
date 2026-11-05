<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Actions\SaveIncidentNote;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Incident;
use Heptapod\Queries\IncidentsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class IncidentNoteController extends Controller
{
    use ResolvesApiPrincipal;

    public function show(Request $request, int $incident, IncidentsReader $reader): JsonResponse
    {
        $user = $this->principal($request);
        $incident = $reader->scoped($user)->findOrFail($incident);

        return $this->note($incident);
    }

    public function update(Request $request, int $incident, IncidentsReader $reader, SaveIncidentNote $save): JsonResponse
    {
        $user = $this->principal($request);
        $data = Validator::make($request->all(), ['note' => ['present', 'nullable', 'string', 'max:10000']])->validate();
        $note = $data['note'];
        if ($note !== null && ! is_string($note)) {
            throw new \LogicException('Validated incident note must be text.');
        }
        $incident = $save($reader->scoped($user)->findOrFail($incident), $user, $note);

        return $this->note($incident);
    }

    private function note(Incident $incident): JsonResponse
    {
        return response()->json(['note' => $incident->notes, 'note_author' => $incident->noteAuthor?->name,
            'note_updated_at' => $incident->note_updated_at?->toIso8601String()]);
    }
}
