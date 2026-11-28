<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RetryOhDearImport;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\ImportRun;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RetryOhDearImportController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, string $run, RetryOhDearImport $claim): JsonResponse
    {
        $importRun = ImportRun::query()->findOrFail($run);
        abort_unless($importRun->user_id === $user->id, 403);
        $claim($importRun);

        return response()->json(['url' => route('settings.import.ohdear.runs.show', $importRun->id)]);
    }
}
