<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Illuminate\Mail\Mailable;

interface MailRenderer
{
    public function mailable(MailModel $mail): Mailable;

    /** @return array{subject: string, html: string} */
    public function preview(MailModel $mail): array;
}
