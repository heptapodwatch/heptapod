<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class NoteBlock implements EvidenceBlock
{
    public function __construct(public string $text, public ?MailTone $tone = null) {}

    public function type(): string
    {
        return 'note';
    }
}
