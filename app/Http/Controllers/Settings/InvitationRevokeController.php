<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RevokeInvitation;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class InvitationRevokeController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Invitation $invitation, RevokeInvitation $revoke): JsonResponse
    {
        $revoke($user, $invitation);

        return response()->json(null, 204);
    }
}
