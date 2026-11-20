<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Export;

enum ColumnType
{
    case Text;
    /** Right-aligned in Markdown. */
    case Number;
}
