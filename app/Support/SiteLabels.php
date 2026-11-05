<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Uri\WhatWg\Url;

/**
 * Names each site so that no two read alike, for notification settings and digest mail.
 * A unique name stays as it is; otherwise the shortest detail that makes it unique is added: the host, then client · host,
 * then the URL path, then the port, and the site ID last.
 */
final class SiteLabels
{
    private const int Stages = 6;

    /**
     * @param  array<int, array{name: string, client: string, url: string}>  $sites  keyed by site ID
     * @return array<int, string>
     */
    public static function for(array $sites): array
    {
        return array_map(static fn (array $identity): string => $identity['label'], self::identities($sites));
    }

    /**
     * @param  array<int, array{name: string, client: string, url: string}>  $sites  keyed by site ID
     * @return array<int, array{label: string, detail: string}>
     */
    public static function identities(array $sites): array
    {
        $stages = array_fill_keys(array_keys($sites), 0);
        do {
            $labels = [];
            foreach ($sites as $id => $site) {
                $labels[$id] = self::text($id, $site, $stages[$id]);
            }
            $counts = array_count_values($labels);
            $changed = false;
            foreach ($labels as $id => $label) {
                // Compare final labels, including names that look like another site's generated detail.
                // Two ID-stage labels cannot collide; a colliding earlier stage must still advance.
                if ($counts[$label] > 1 && $stages[$id] < self::Stages - 1) {
                    $stages[$id]++;
                    $changed = true;
                }
            }
        } while ($changed);

        $identities = [];
        foreach ($sites as $id => $site) {
            $identities[$id] = ['label' => $labels[$id], 'detail' => self::detail($id, $site, max($stages[$id], 2))];
        }

        return $identities;
    }

    /**
     * The label ending in the site ID, for when space forces a shortened label: shortening keeps the end, and the ID is
     * unique even where the shortened paths would read alike.
     *
     * @param  array{name: string, client: string, url: string}  $site
     */
    public static function withId(int $id, array $site): string
    {
        return self::text($id, $site, self::Stages - 1);
    }

    /** @param array{name: string, client: string, url: string} $site */
    private static function text(int $id, array $site, int $stage): string
    {
        return $stage === 0 ? $site['name'] : $site['name'].' ('.self::detail($id, $site, $stage).')';
    }

    /** @param array{name: string, client: string, url: string} $site */
    private static function detail(int $id, array $site, int $stage): string
    {
        // The WHATWG parser is the one behind the browser's URL class: it drops default ports and canonicalises the path.
        $url = Url::parse($site['url']) ?? throw new \LogicException('A site URL must parse as a URL.');
        $hostname = $url->getAsciiHost() ?? throw new \LogicException('A site URL must have a host.');
        $port = $url->getPort();
        $location = static fn (bool $withPort): string => $hostname.($withPort && $port !== null ? ':'.$port : '').$url->getPath();
        // Preserve the stored host spelling for the shortest labels.
        $host = parse_url($site['url'], PHP_URL_HOST);
        $host = is_string($host) ? $host : $site['url'];

        return match ($stage) {
            1 => $host,
            2 => $site['client'].' · '.$host,
            3 => $site['client'].' · '.$location(false),
            4 => $site['client'].' · '.$location(true),
            default => $site['client'].' · '.$location(true).' · #'.$id,
        };
    }
}
