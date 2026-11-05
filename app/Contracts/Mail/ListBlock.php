<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class ListBlock implements EvidenceBlock
{
    /** @param list<array{text: string, meta: string}> $items */
    public function __construct(public string $title, public array $items) {}

    public function type(): string
    {
        return 'list';
    }
}
