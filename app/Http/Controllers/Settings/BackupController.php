<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RequestBackup;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\BackupHistoryReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BackupController extends Controller
{
    public function index(Request $request, BackupHistoryReader $history): JsonResponse
    {
        $this->owner($request);

        return response()->json($history->read());
    }

    public function store(Request $request, RequestBackup $backup): JsonResponse
    {
        return response()->json(['id' => $backup($this->owner($request))], 202);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);

        return $user;
    }
}
