<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Export;

final readonly class Column
{
    public function __construct(public string $label, public ColumnType $type = ColumnType::Text) {}
}
