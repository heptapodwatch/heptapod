<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ChangeMemberAccess;
use Heptapod\Actions\DeactivateMember;
use Heptapod\Actions\InviteMember;
use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Data\Ui\MemberSettingsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\MembersReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MembersController extends Controller
{
    use AuthorizesSettingsOperator;

    public function edit(Request $request, MembersReader $reader): Response
    {
        return Inertia::render('settings/members', new MemberSettingsPageData(
            roster: $reader->read($this->operator($request)),
        ));
    }

    public function index(Request $request, MembersReader $reader): JsonResponse
    {
        return response()->json($reader->read($this->operator($request))->toArray());
    }

    public function store(Request $request, InviteMember $invite): JsonResponse
    {
        $actor = $this->operator($request);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $invitation = $invite($actor, $attributes);

        return response()->json(['id' => $invitation->id, 'message' => MailDeliveryMode::logOnly()
            ? __('Invitation created; email not delivered: mail is not configured') : __('Invitation sent.')], 201);
    }

    public function update(Request $request, User $member, ChangeMemberAccess $change): JsonResponse
    {
        $actor = $this->operator($request);
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $change($actor, $member, $attributes);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, User $member, DeactivateMember $deactivate): JsonResponse
    {
        $deactivate($this->operator($request), $member);

        return response()->json(null, 204);
    }
}
