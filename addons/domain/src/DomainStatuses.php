<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Illuminate\Support\Str;

final class DomainStatuses
{
    /** @return array<string, bool> */
    public static function normalize(mixed $statuses): array
    {
        if (! is_array($statuses)) {
            throw new \UnexpectedValueException('Domain registration statuses must be an array.');
        }
        $flags = [];
        foreach ($statuses as $status) {
            if (! is_string($status)) {
                throw new \UnexpectedValueException('Domain registration statuses must be strings.');
            }
            $flags[Str::snake(str_replace(' ', '_', trim(explode(' https://', $status)[0])))] = true;
        }
        ksort($flags);

        return $flags;
    }

    /** @param array<string, bool> $flags */
    public static function dangerous(array $flags): bool
    {
        return array_intersect(array_keys($flags), ['pending_delete', 'redemption_period', 'client_hold', 'server_hold']) !== [];
    }
}
