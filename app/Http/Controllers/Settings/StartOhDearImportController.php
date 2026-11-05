<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\StartOhDearImport;
use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\ImportRun;
use Heptapod\Support\ImportAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StartOhDearImportController extends Controller
{
    use AuthorizesSettingsOperator;

    public function __invoke(Request $request, string $run, StartOhDearImport $claim): JsonResponse
    {
        $user = $this->operator($request);
        $importRun = ImportRun::query()->findOrFail($run);
        abort_unless($importRun->user_id === $user->id, 403);
        $target = ['required', 'array:client_id,new_client'];
        $client = ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('archived_at')];
        /** @var array{expected: int, groups?: array<string, array{client_id?: int|null, new_client?: string|null}>, overrides?: array<string, array{client_id?: int|null, new_client?: string|null}>} $data */
        $data = Validator::make($request->all(), [
            'expected' => ['required', 'integer', Rule::in([$importRun->expected])],
            'groups' => ['present', 'array'], 'groups.*' => $target,
            'groups.*.client_id' => [...$client, 'required_without:groups.*.new_client'], 'groups.*.new_client' => ['nullable', 'string', 'max:120'],
            'overrides' => ['present', 'array'], 'overrides.*' => $target,
            'overrides.*.client_id' => [...$client, 'required_without:overrides.*.new_client'], 'overrides.*.new_client' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $assignments = ImportAssignments::resolve($importRun->plan, $this->targets($data['groups'] ?? []), $this->targets($data['overrides'] ?? []));
        $claim($importRun, $assignments);

        return response()->json(['url' => route('settings.import.ohdear.runs.show', $importRun->id)], 201);
    }

    /**
     * @param  array<string, array{client_id?: int|null, new_client?: string|null}>  $targets
     * @return array<string, array{client_id: int|null, new_client: string|null}>
     */
    private function targets(array $targets): array
    {
        $normalized = [];
        foreach ($targets as $key => $target) {
            $name = isset($target['new_client']) ? trim($target['new_client']) : null;
            $normalized[$key] = ['client_id' => $target['client_id'] ?? null, 'new_client' => $name === '' ? null : $name];
        }

        return $normalized;
    }
}
