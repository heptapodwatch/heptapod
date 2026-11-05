<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SetUpdateChecksEnabled;
use Heptapod\Data\Ui\UpdatesPageData;
use Heptapod\Data\Ui\UpdatesSettingsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\BackupHistoryReader;
use Heptapod\Queries\UpdateStatusReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

final class UpdateStatusController extends Controller
{
    public function edit(Request $request, UpdateStatusReader $reader, BackupHistoryReader $backups): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);

        return Inertia::render('settings/updates', new UpdatesPageData(
            updatesPage: UpdatesSettingsPageData::from([
                'updates' => $reader->read(),
                'backups' => $user->hasRole('Owner') ? $backups->read() : null,
            ]),
        ));
    }

    public function show(Request $request, UpdateStatusReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);

        return response()->json($reader->read());
    }

    public function update(Request $request, SetUpdateChecksEnabled $set, UpdateStatusReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);
        /** @var array{enabled: bool} $data */
        $data = Validator::make($request->all(), ['enabled' => ['required', 'boolean']])->validate();
        $set($data['enabled']);

        return response()->json($reader->read());
    }
}
