<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Robots directives are bounded inputs; URL validation belongs to the guarded fetch boundary. */
final class SitemapLocations
{
    /** @return list<string> */
    public static function fromRobots(string $body, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Sitemap discovery requires a positive limit.');
        }
        preg_match_all('/^\s*Sitemap\s*:\s*([^\r\n#]+?)[ \t]*(?:#.*)?$/mi', $body, $matches);

        return array_slice(array_values(array_unique(array_map(trim(...), $matches[1]))), 0, $limit);
    }
}
