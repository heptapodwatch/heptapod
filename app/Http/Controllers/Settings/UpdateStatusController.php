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
use Heptapod\Support\ReleaseNotes;
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
                'updates' => $this->withNotes($reader),
                'backups' => $user->hasRole('Owner') ? $backups->read($user) : null,
            ]),
        ));
    }

    public function show(Request $request, UpdateStatusReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);

        return response()->json($this->withNotes($reader));
    }

    public function update(Request $request, SetUpdateChecksEnabled $set, UpdateStatusReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);
        /** @var array{enabled: bool} $data */
        $data = Validator::make($request->all(), ['enabled' => ['required', 'boolean']])->validate();
        $set($data['enabled']);

        return response()->json($this->withNotes($reader));
    }

    /**
     * The update status with each skipped release's full note as safe HTML. Only this page renders notes; the shell
     * prop and System health read the status without them.
     *
     * @return array<string, mixed>
     */
    private function withNotes(UpdateStatusReader $reader): array
    {
        // This page reads the database anyway; it re-primes the shell summary (after a toggle, or a cleared cache).
        $reader->primeShellSummary();
        $updates = $reader->read();
        if ($updates['releases'] === null) {
            return $updates;
        }
        $notes = $reader->notes();
        $updates['releases'] = array_map(static fn (array $release): array => [...$release,
            'notes_html' => isset($notes[$release['tag']]) ? ReleaseNotes::html($notes[$release['tag']]['body']) : null,
            'notes_truncated' => $notes[$release['tag']]['body_truncated'] ?? false,
        ], $updates['releases']);

        return $updates;
    }
}
