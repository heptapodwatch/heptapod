<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;

final class BrowserResponseFraming
{
    public function __invoke(RequestHandled $event): void
    {
        $response = $event->response;
        if (! in_array($response->getStatusCode(), [Response::HTTP_NO_CONTENT, Response::HTTP_NOT_MODIFIED], true)
            || $response->getContent() !== '') {
            return;
        }

        // TODO: Remove after a Pest browser release includes #254 or an Amp release newer than 3.4.6 includes #392.
        // Then rerun the ordered InvitationBrowserTest → MembersBrowserTest pair.
        // https://github.com/pestphp/pest-plugin-browser/pull/254
        // https://github.com/amphp/http-server/pull/392
        // Pest's Amp bridge chunks empty keep-alive responses; close these bodyless responses instead.
        $response->headers->remove('Transfer-Encoding');
        $response->headers->remove('Content-Length');
        $response->headers->set('Connection', 'close');
    }
}
