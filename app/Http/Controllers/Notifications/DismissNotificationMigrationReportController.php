<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\DismissNotificationMigrationReport;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Owner hides the stored migration report on the team page for the instance; it stays in the audit log. */
final class DismissNotificationMigrationReportController extends Controller
{
    public function __invoke(Request $request, DismissNotificationMigrationReport $dismiss): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->disabled_at === null && $user->hasRole('Owner'), 403);
        $dismiss($user);

        return response()->json(null, 204);
    }
}
