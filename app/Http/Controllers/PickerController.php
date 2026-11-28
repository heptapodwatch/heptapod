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
        /** @var array{q?: string|null, ids?: string, client?: int|string, icon?: bool|string, archived?: bool|string, browse?: bool|string} $data */
        $data = Validator::make($request->query(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Comma-separated, so a batch of IdLimit ids stays a short GET.
            'ids' => ['sometimes', 'string', 'regex:/^[1-9][0-9]{0,18}(,[1-9][0-9]{0,18})*$/', static function (string $attribute, string $value, \Closure $fail): void {
                if (substr_count($value, ',') >= PickerReader::IdLimit) {
                    $fail('At most '.PickerReader::IdLimit.' ids per read.');
                }
            }],
            'archived' => ['sometimes', 'boolean'],
            ...($kind === 'sites' ? ['client' => ['sometimes', 'integer', 'min:1'], 'icon' => ['sometimes', 'boolean']] : ['browse' => ['sometimes', 'boolean']]),
        ])->validate();
        $search = trim($data['q'] ?? '');
        $ids = isset($data['ids']) ? array_values(array_unique(array_map(intval(...), explode(',', $data['ids'])))) : null;
        $flag = static fn (string $key): bool => filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(($kind === 'sites'
            ? $reader->sites($user, $search, $ids, isset($data['client']) ? (int) $data['client'] : null, $flag('icon'), $flag('archived'))
            : $reader->clients($user, $search, $ids, $flag('archived'), $flag('browse')))->toArray());
    }
}
