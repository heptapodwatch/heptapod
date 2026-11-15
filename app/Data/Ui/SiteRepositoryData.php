<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Models\SiteRepository;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One repository link of a site, in its canonical `https://host/path` form. */
#[TypeScript]
final class SiteRepositoryData extends Data
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $label,
    ) {}

    /** @return list<self> the site's links in their saved order */
    public static function forSite(int $siteId): array
    {
        $query = SiteRepository::query();
        $query->getQuery()->where('site_id', $siteId)->orderBy('position');

        return array_values($query->get()->map(static fn (SiteRepository $repository): self => new self($repository->url, $repository->label))->all());
    }
}
