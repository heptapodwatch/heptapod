<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\User;
use Heptapod\Queries\PickerReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** The server-searched site and client pickers: `q` searches, `ids` names the chosen values instead. */
final class PickerController extends Controller
{
    public function __invoke(Request $request, string $kind, PickerReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        /** @var array{q?: string|null, ids?: list<int|string>, client?: int|string, icon?: bool|string, archived?: bool|string, browse?: bool|string} $data */
        $data = Validator::make($request->query(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'ids' => ['sometimes', 'array', 'list', 'max:'.PickerReader::IdLimit], 'ids.*' => ['integer', 'min:1'],
            ...($kind === 'sites'
                ? ['client' => ['sometimes', 'integer', 'min:1'], 'icon' => ['sometimes', 'boolean']]
                : ['archived' => ['sometimes', 'boolean'], 'browse' => ['sometimes', 'boolean']]),
        ])->validate();
        $search = trim($data['q'] ?? '');
        $ids = isset($data['ids']) ? array_map(intval(...), $data['ids']) : null;
        $flag = static fn (string $key): bool => filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(($kind === 'sites'
            ? $reader->sites($user, $search, $ids, isset($data['client']) ? (int) $data['client'] : null, $flag('icon'))
            : $reader->clients($user, $search, $ids, $flag('archived'), $flag('browse')))->toArray());
    }
}
