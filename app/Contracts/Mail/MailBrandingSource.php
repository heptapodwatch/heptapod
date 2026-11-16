<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

interface MailBrandingSource
{
    /** The saved branding, read at render time so a running worker sends the current look. */
    public function current(): MailBranding;
}
