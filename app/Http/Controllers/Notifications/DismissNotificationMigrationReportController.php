<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Heptapod\Actions\Notifications\DismissNotificationMigrationReport;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** The Owner hides the stored migration report on the team page for the instance; it stays in the audit log. */
final class DismissNotificationMigrationReportController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, DismissNotificationMigrationReport $dismiss): JsonResponse
    {
        $dismiss($user);

        return response()->json(null, 204);
    }
}
