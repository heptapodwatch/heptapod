<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class FactsBlock implements EvidenceBlock
{
    /** @param list<array{label: string, value: Cell}> $rows */
    public function __construct(public string $title, public array $rows) {}

    public function type(): string
    {
        return 'facts';
    }
}
