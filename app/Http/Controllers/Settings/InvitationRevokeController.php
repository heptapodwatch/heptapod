<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\RevokeInvitation;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationRevokeController extends Controller
{
    public function __invoke(Request $request, Invitation $invitation, RevokeInvitation $revoke): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $revoke($user, $invitation);

        return response()->json(null, 204);
    }
}
