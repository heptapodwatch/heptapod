<?php

declare(strict_types=1);

namespace Heptapod\Http\Responses;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns the same generic status response for unknown, disabled, or broker-throttled addresses
 * as for a sent reset link so /forgot-password cannot enumerate accounts.
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return new SuccessfulPasswordResetLinkRequestResponse(PasswordBroker::RESET_LINK_SENT)->toResponse($request);
    }
}
