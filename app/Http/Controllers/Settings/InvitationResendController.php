<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ResendInvitation;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InvitationResendController extends Controller
{
    public function __invoke(Request $request, Invitation $invitation, ResendInvitation $resend): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $resend($user, $invitation);

        return response()->noContent();
    }
}
