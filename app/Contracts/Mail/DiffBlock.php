<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class DiffBlock implements EvidenceBlock
{
    /** @param list<array{sign: '+'|'-'|' ', text: string, tone: MailTone}> $lines
     * @param  array{label: string, url: string}|null  $action
     */
    public function __construct(public string $title, public array $lines, public ?array $action = null) {}

    public function type(): string
    {
        return 'diff';
    }
}
