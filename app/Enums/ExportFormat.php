<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The formats every export offers; the value is the file extension and the route's {format}. */
#[TypeScript]
enum ExportFormat: string
{
    case Csv = 'csv';
    case Markdown = 'md';
    case Text = 'txt';

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Markdown => 'text/markdown; charset=UTF-8',
            self::Text => 'text/plain; charset=UTF-8',
        };
    }
}
