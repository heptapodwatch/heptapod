<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Illuminate\Support\Facades\DB;

final class GenericSiteIcons
{
    /** @var array<string, true>|null */
    private ?array $hashes = null;

    public function url(int $siteId, ?string $hash): ?string
    {
        return $hash === null || $this->contains($hash) ? null : route('sites.icon', ['site' => $siteId, 'hash' => $hash], false);
    }

    public function contains(?string $hash): bool
    {
        if ($hash === null) {
            return false;
        }
        // Request-scoped, portfolio-bounded: one aggregate over configured sites, not history.
        // No persistent cache; icon changes, client moves and deletions apply on the next request.
        if ($this->hashes === null) {
            /** @var list<string> $hashes */
            $hashes = DB::table('sites')->whereNotNull('icon_hash')->groupBy('icon_hash')
                ->havingRaw('count(distinct client_id) >= 2')->pluck('icon_hash')->all();
            $this->hashes = array_fill_keys($hashes, true);
        }

        return isset($this->hashes[$hash]);
    }
}
