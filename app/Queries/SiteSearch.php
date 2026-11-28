<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Database\Query\Builder;

/** How a typed search matches sites and clients wherever the server searches them (pickers, the site directory). */
final class SiteSearch
{
    /**
     * Every word matches the site's name, its URL or its client's name ("No client" for the Internal client). Narrows
     * a query over `sites`; it reads no other site, so it adds nothing beyond the caller's visibility.
     */
    public static function whereMatches(Builder $sites, string $search): void
    {
        foreach (self::patterns($search) as $pattern) {
            $sites->where(static fn (Builder $match): Builder => $match->whereRaw(Site::displayNameSql().' ILIKE ?', [$pattern])
                ->orWhere('sites.url', 'ilike', $pattern)
                ->orWhereIn('sites.client_id', static fn (Builder $clients): Builder => $clients->select('clients.id')->from('clients')
                    ->whereRaw(Client::displayNameSql().' ILIKE ?', [__('No client'), $pattern])));
        }
    }

    /** Every word matches the client's display name. */
    public static function whereClientMatches(Builder $clients, string $search): void
    {
        foreach (self::patterns($search) as $pattern) {
            $clients->whereRaw(Client::displayNameSql().' ILIKE ?', [__('No client'), $pattern]);
        }
    }

    /**
     * One case-insensitive pattern per word of the search, at most five words.
     *
     * @return list<string>
     */
    private static function patterns(string $search): array
    {
        $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            throw new \LogicException('A search splits into words.');
        }

        return array_map(static fn (string $word): string => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%', array_slice($words, 0, 5));
    }
}
