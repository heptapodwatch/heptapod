<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

enum MailTone: string
{
    case Down = 'down';
    case Failed = 'failed';
    case Warning = 'warn';
    case Recovered = 'ok';
    case CheckError = 'error';
    case Changed = 'info';
    case Neutral = 'neutral';

    public function color(): string
    {
        return match ($this) {
            self::Down, self::Failed => 'down',
            self::Warning => 'warn',
            self::Recovered => 'ok',
            self::CheckError => 'error',
            self::Changed => 'info',
            self::Neutral => 'fg3',
        };
    }
}
