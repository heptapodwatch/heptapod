<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RetryOhDearImport;
use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\ImportRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetryOhDearImportController extends Controller
{
    use AuthorizesSettingsOperator;

    public function __invoke(Request $request, string $run, RetryOhDearImport $claim): JsonResponse
    {
        $user = $this->operator($request);
        $importRun = ImportRun::query()->findOrFail($run);
        abort_unless($importRun->user_id === $user->id, 403);
        $claim($importRun);

        return response()->json(['url' => route('settings.import.ohdear.runs.show', $importRun->id)]);
    }
}
