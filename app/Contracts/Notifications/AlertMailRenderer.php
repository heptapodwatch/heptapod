<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Notifications;

use Illuminate\Mail\Mailable;

interface AlertMailRenderer
{
    /** The unaddressed alert mail in the destination's locale and timezone. */
    public function mailable(AlertMessage $message, DestinationConfig $destination): Mailable;
}
