<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

/**
 * The operator's look for every email. Sender name and footer apply to Branded and Plain; logos and accent only to Branded.
 * A null field keeps the Heptapod default.
 */
final readonly class MailBranding
{
    public function __construct(
        public ?string $senderName = null,
        public ?string $accent = null,
        public ?string $footer = null,
        public ?MailLogo $logo = null,
        public ?MailLogo $logoDark = null,
    ) {
        if ($logoDark instanceof MailLogo && ! $logo instanceof MailLogo) {
            throw new \LogicException('A dark-mode mail logo needs a logo.');
        }
    }
}
