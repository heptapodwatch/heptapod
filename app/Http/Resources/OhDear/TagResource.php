<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Tags\Tag;

final class TagResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /** @param list<int>|null $siteIds */
    public function __construct(mixed $resource, private readonly ?array $siteIds = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Tag $tag */
        $tag = $this->resource;
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $sites = Site::withAnyTags([$tag]);
        $sites->getQuery()->whereIn('sites.id', app(SitePolicy::class)->visibleSites($user)->getQuery()->select('sites.id'));

        return ['id' => $tag->id, 'team_id' => 1, 'team_name' => app(InstanceSettings::class)->name,
            'name' => $tag->getTranslation('name', 'en'), 'slug' => $tag->getTranslation('slug', 'en'),
            'created_at' => $tag->created_at?->utc()->format('Y-m-d H:i:s'), 'updated_at' => $tag->updated_at?->utc()->format('Y-m-d H:i:s'),
            'sites' => $this->siteIds ?? $sites->pluck('id')->all()];
    }
}
