<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ResendInvitation;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Invitation;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final class InvitationResendController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Invitation $invitation, ResendInvitation $resend): Response
    {
        $resend($user, $invitation);

        return response()->noContent();
    }
}
