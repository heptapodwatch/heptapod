<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Contracts\ServersAccess;
use Heptapod\Contracts\ServerSearch;

/** One read, bounded by $limit; nothing is read while servers are off. */
final readonly class DatabaseServerSearch implements ServerSearch
{
    public function __construct(private ServersAccess $access, private ServerReader $reader) {}

    /** @return list<ServerRowData> */
    public function matching(string $search, int $limit): array
    {
        return $this->access->enabled() ? $this->reader->matching(CarbonImmutable::now(), $search, $limit) : [];
    }
}
