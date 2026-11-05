<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

/** The HTML layout a mail is rendered in: Branded (lockup, card, chips) or Plain (simple text, no images). */
enum MailDesign: string
{
    case Branded = 'branded';
    case Plain = 'plain';
}
