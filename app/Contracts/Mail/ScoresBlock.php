<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class ScoresBlock implements EvidenceBlock
{
    /** @param list<array{label: string, value: int, min: int|null}> $items */
    public function __construct(public string $title, public array $items, public ?string $note = null) {}

    public function type(): string
    {
        return 'scores';
    }
}
