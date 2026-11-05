<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Sitemap execution shares one host gate across documents, batches and redirect hops. */
interface PacedHttpClient
{
    /** Null means admission was withheld or a budget-clamped transfer timed out, not a target finding. */
    public function fetch(FetchRequest $request): ?FetchResult;

    /**
     * @param  array<array-key, FetchRequest>  $requests  At most 500 reachability requests.
     * @return array<array-key, FetchResult|null> Unexpected errors propagate.
     */
    public function fetchMany(array $requests): array;
}
