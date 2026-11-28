<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class NoteBlock implements EvidenceBlock
{
    public function __construct(public string $text, public ?MailTone $tone = null) {}

    /** Raw retention removed the facts this block would show. */
    public static function detailsNotRetained(string $locale): self
    {
        return new self(__('notifications.mail_details_not_retained', locale: $locale));
    }

    public function type(): string
    {
        return 'note';
    }
}
