<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RequestBackup;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\BackupHistoryReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class BackupController extends Controller
{
    public function index(#[CurrentUser] User $user, BackupHistoryReader $history): JsonResponse
    {
        return response()->json($history->read($user));
    }

    public function store(#[CurrentUser] User $user, RequestBackup $backup): JsonResponse
    {
        return response()->json(['id' => $backup($user)], 202);
    }
}
