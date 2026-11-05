<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

final readonly class TableBlock implements EvidenceBlock
{
    /** @param list<string> $head
     * @param  list<list<Cell>>  $rows
     */
    public function __construct(public string $title, public array $head, public array $rows, public ?string $more = null) {}

    public function type(): string
    {
        return 'table';
    }
}
