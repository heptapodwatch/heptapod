<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Heptapod\Data\Ui\TagData;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

final readonly class TagsReader
{
    public function __construct(private SitePolicy $policy) {}

    /** @return list<TagData> */
    public function read(User $user): array
    {
        $tags = Tag::query();
        if ($user->isRestrictedMember()) {
            // Keep membership semi-joins out of the tag plan when bulk writes have no planner statistics.
            $visibleSiteIds = $this->policy->visibleSiteIds($user);
            /** @var list<int> $tagIds */
            $tagIds = DB::table('taggables')
                ->whereIntegerInRaw('taggable_id', $visibleSiteIds)
                ->where('taggable_type', (new Site)->getMorphClass())
                ->distinct()->pluck('tag_id')->all();
            $tags->getQuery()->whereIntegerInRaw('tags.id', $tagIds);
        }
        $tags->getQuery()->where('type', 'sites')->orderBy('name->en')->orderBy('id');
        $rows = [];
        foreach ($tags->get() as $tag) {
            $name = $tag->getTranslation('name', 'en');
            if (! is_string($name)) {
                throw new \LogicException('Canonical tag name must be a string.');
            }
            $rows[] = new TagData($tag->id, $name);
        }

        return $rows;
    }
}
