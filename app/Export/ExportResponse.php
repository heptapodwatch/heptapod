<?php

declare(strict_types=1);

namespace Heptapod\Export;

use Heptapod\Contracts\Export\Column;
use Heptapod\Contracts\Export\ColumnType;
use Heptapod\Contracts\Export\Table;
use Heptapod\Enums\ExportFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams any Table in any ExportFormat. Controllers authorize and build the Table; this only encodes it. */
final class ExportResponse
{
    public static function make(Table $table, ExportFormat $format): StreamedResponse
    {
        return response()->streamDownload(static function () use ($table, $format): void {
            // Findings providers share-lock their accepted snapshot until paging finishes, so retention cannot delete it mid-export.
            DB::transaction(static function () use ($table, $format): void {
                $output = fopen('php://output', 'w');
                if ($output === false) {
                    throw new \RuntimeException('Cannot open the export stream.');
                }
                self::write($table, $format, $output);
                if (! fclose($output)) {
                    throw new \RuntimeException('Cannot close the export stream.');
                }
            });
        }, $table->filename.'.'.$format->value, ['Content-Type' => $format->contentType(), 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no']);
    }

    /** @param resource $output */
    private static function write(Table $table, ExportFormat $format, mixed $output): void
    {
        $put = static function (string $text) use ($output): void {
            if (fwrite($output, $text) !== strlen($text)) {
                throw new \RuntimeException('Cannot write the export.');
            }
        };
        $labels = array_map(static fn (Column $column): string => $column->label, $table->columns);
        $facts = array_map(static fn (array $fact): string => $fact['label'].': '.$fact['value'], $table->facts);
        $put(match ($format) {
            ExportFormat::Csv => Csv::ByteOrderMark.Csv::line($labels),
            ExportFormat::Markdown => implode("\n", ['# '.self::oneLine($table->title), '',
                ...array_map(static fn (string $fact): string => '- '.self::oneLine($fact), $facts), ...($facts === [] ? [] : ['']),
                self::markdownRow($labels),
                self::markdownRow(array_map(static fn (Column $column): string => $column->type === ColumnType::Number ? '---:' : '---', $table->columns), escape: false)])."\n",
            ExportFormat::Text => implode("\n", [$table->title, ...$facts])."\n",
        });
        $written = 0;
        $seen = 0;
        foreach ($table->rows as $row) {
            $seen++;
            if ($written >= Table::RowLimit) {
                if ($table->total !== null) {
                    break;
                }

                continue;
            }
            $put(match ($format) {
                ExportFormat::Csv => Csv::line($row),
                ExportFormat::Markdown => self::markdownRow($row)."\n",
                ExportFormat::Text => "\n".self::textBlock($table->columns, $row),
            });
            $written++;
        }
        $total = $table->total ?? $seen;
        if ($total > $written && $format !== ExportFormat::Csv) {
            $put("\n".__('export.capped', ['shown' => Number::format($written, locale: app()->getLocale()), 'total' => Number::format($total, locale: app()->getLocale())])."\n");
        }
    }

    /** @param list<string> $cells */
    private static function markdownRow(array $cells, bool $escape = true): string
    {
        return '| '.implode(' | ', $escape ? array_map(self::markdownCell(...), $cells) : $cells).' |';
    }

    private static function markdownCell(string $value): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', '<br>', str_replace(['\\', '|'], ['\\\\', '\\|'], $value));
    }

    /** A title, fact or plain-text value stays one line; a stray line break would end a heading, list item or block. */
    private static function oneLine(string $value): string
    {
        return (string) preg_replace('/\s*[\r\n]+\s*/', ' ', $value);
    }

    /**
     * The first cell is the row's title line (a number keeps its label: "ID 42"); the rest are "Label: value" lines,
     * empty ones left out.
     *
     * @param  list<Column>  $columns
     * @param  list<string>  $row
     */
    private static function textBlock(array $columns, array $row): string
    {
        $lines = [($columns[0]->type === ColumnType::Number ? $columns[0]->label.' ' : '').self::oneLine($row[0])];
        foreach (array_slice($row, 1, preserve_keys: true) as $index => $value) {
            if ($value !== '') {
                $lines[] = $columns[$index]->label.': '.self::oneLine($value);
            }
        }

        return implode("\n", $lines)."\n";
    }
}
