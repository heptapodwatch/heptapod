<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** The app-owned headers every outbound check request sends unless the request sets its own value. */
final class PublicRequestProfile
{
    /** @return array<string, string> */
    public static function headers(): array
    {
        return [
            'User-Agent' => 'HeptapodBot/'.config()->string('heptapod.version').' (+https://heptapod.watch/bot)',
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en,de;q=0.9,*;q=0.5',
        ];
    }
}
