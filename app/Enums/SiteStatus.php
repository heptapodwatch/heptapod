<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Contracts\CheckKey;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SiteStatus: string
{
    case Maintenance = 'maintenance';
    case Paused = 'paused';
    case Disabled = 'disabled';
    case Unavailable = 'unavailable';
    case Down = 'down';
    case Failed = 'failed';
    case CheckError = 'check-error';
    case Warning = 'warning';
    case Pending = 'pending';
    case Ok = 'ok';

    /** Down outranks non-uptime failures; inactive sites sort last, with locale-aware name ties. */
    public function severity(): int
    {
        return match ($this) {
            self::Down => 0,
            self::Failed => 1,
            self::CheckError => 2,
            self::Warning => 3,
            self::Pending => 4,
            self::Ok => 5,
            self::Maintenance => 6,
            self::Paused, self::Disabled, self::Unavailable => 7,
        };
    }

    /** An open incident's state: a check error, an uptime outage or a non-uptime failure. */
    public static function forIncident(CheckKey $check, IncidentCause $cause): self
    {
        return match (true) {
            $cause === IncidentCause::Errored => self::CheckError,
            $check === CheckKey::Uptime => self::Down,
            default => self::Failed,
        };
    }

    /** The glossary word (translation key, EN/DE) for the attention states. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Down => 'Down', self::Failed => 'Failed', self::CheckError => 'Check error', self::Warning => 'Warning',
            default => throw new \LogicException("Site status {$this->value} has no server-side label."),
        };
    }

    /**
     * States that need action now, worst first.
     *
     * @return list<self>
     */
    public static function attention(): array
    {
        return [self::Down, self::Failed, self::CheckError, self::Warning];
    }

    /** The worst rank outside the attention set: attention states rank below it. */
    public static function quietRank(): int
    {
        return count(self::attention());
    }

    /**
     * Whether a state column holds an attention state, as SQL.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function attentionSql(string $column): string
    {
        $values = '';
        foreach (self::attention() as $status) {
            $values .= ($values === '' ? '' : ', ')."'".$status->literal()."'";
        }

        return $column.' IN ('.$values.')';
    }

    /**
     * The severity rank of a state column as SQL.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function rankSql(string $column): string
    {
        return self::caseSql($column, self::cases(), '');
    }

    /**
     * The severity rank of a state column among attention states; every other state ranks quietRank().
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function attentionRankSql(string $column): string
    {
        return self::caseSql($column, self::attention(), ' ELSE '.self::quietRankLiteral());
    }

    /**
     * @param  literal-string  $column
     * @param  list<self>  $statuses
     * @param  literal-string  $else
     * @return literal-string
     */
    private static function caseSql(string $column, array $statuses, string $else): string
    {
        $rank = 'CASE '.$column;
        foreach ($statuses as $status) {
            $rank .= " WHEN '".$status->literal()."' THEN ".$status->severityLiteral();
        }

        return $rank.$else.' END';
    }

    /**
     * The value as an SQL-safe literal: fixed enum values, never user input.
     *
     * @return literal-string
     */
    private function literal(): string
    {
        return match ($this) {
            self::Maintenance => 'maintenance', self::Paused => 'paused', self::Disabled => 'disabled',
            self::Unavailable => 'unavailable', self::Down => 'down', self::Failed => 'failed',
            self::CheckError => 'check-error', self::Warning => 'warning', self::Pending => 'pending', self::Ok => 'ok',
        };
    }

    /** @return literal-string */
    private function severityLiteral(): string
    {
        return self::rankLiteral($this->severity());
    }

    /** @return literal-string */
    public static function quietRankLiteral(): string
    {
        return self::rankLiteral(self::quietRank());
    }

    /** @return literal-string */
    private static function rankLiteral(int $rank): string
    {
        return match ($rank) {
            0 => '0', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7',
            default => throw new \LogicException("Site status rank {$rank} has no SQL literal."),
        };
    }
}
