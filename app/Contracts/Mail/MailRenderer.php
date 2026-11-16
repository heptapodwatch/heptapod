<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Illuminate\Mail\Mailable;

interface MailRenderer
{
    public function mailable(MailModel $mail): Mailable;

    /**
     * Without $branding the saved branding applies; Settings → Email passes its unsaved draft.
     *
     * @return array{subject: string, html: string}
     */
    public function preview(MailModel $mail, ?MailBranding $branding = null): array;
}
