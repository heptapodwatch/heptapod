<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Site;
use Illuminate\Support\Collection;
use Spatie\Tags\Tag;

final class SyncSiteTags
{
    /** @param list<string> $tags */
    public function __invoke(Site $site, array $tags): void
    {
        /** @var Collection<int, Tag> $canonical */
        $canonical = Tag::findOrCreate($tags, 'sites', 'en');
        $site->syncTagsWithType($canonical, 'sites');
    }
}
