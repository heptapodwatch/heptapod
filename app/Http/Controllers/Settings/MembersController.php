<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ChangeMemberAccess;
use Heptapod\Actions\DeactivateMember;
use Heptapod\Actions\InviteMember;
use Heptapod\Contracts\Mail\MailDeliveryMode;
use Heptapod\Data\Ui\MemberSettingsPageData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Queries\MembersReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MembersController extends Controller
{
    public function edit(#[CurrentUser] User $user, MembersReader $reader): Response
    {
        return Inertia::render('settings/members', new MemberSettingsPageData(
            roster: $reader->read($user),
        ));
    }

    public function index(#[CurrentUser] User $user, MembersReader $reader): JsonResponse
    {
        return response()->json($reader->read($user)->toArray());
    }

    public function store(#[CurrentUser] User $actor, Request $request, InviteMember $invite): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $invitation = $invite($actor, $attributes);

        return response()->json(['id' => $invitation->id, 'message' => MailDeliveryMode::logOnly()
            ? __('Invitation created; email not delivered: mail is not configured') : __('Invitation sent.')], 201);
    }

    public function update(#[CurrentUser] User $actor, Request $request, User $member, ChangeMemberAccess $change): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        $change($actor, $member, $attributes);

        return response()->json(null, 204);
    }

    public function destroy(#[CurrentUser] User $user, User $member, DeactivateMember $deactivate): JsonResponse
    {
        $deactivate($user, $member);

        return response()->json(null, 204);
    }
}
