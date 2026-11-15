<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Site;
use Heptapod\Models\SiteRepository;
use Heptapod\Support\RepositoryUrl;

final class SyncSiteRepositories
{
    /**
     * Replaces the site's repository links with the given validated rows, in their order. An unchanged list writes
     * nothing; a change is audited on the site with the old and new lists.
     *
     * @param  list<array{url: string, label?: string|null}>  $rows
     */
    public function __invoke(Site $site, array $rows): void
    {
        $new = array_map(static fn (array $row): array => [
            'url' => RepositoryUrl::canonical($row['url']) ?? throw new \LogicException('Repository URLs are validated before the sync.'),
            'label' => ($label = trim($row['label'] ?? '')) === '' ? null : $label,
        ], $rows);
        $old = $this->current($site);
        if ($new === $old) {
            return;
        }
        SiteRepository::query()->where('site_id', $site->id)->delete();
        foreach ($new as $position => $repository) {
            $site->repositories()->create([...$repository, 'position' => $position]);
        }
        activity('audit')->performedOn($site)->event('site.repositories.updated')
            ->withProperties(['old' => ['repositories' => $old], 'attributes' => ['repositories' => $new]])
            ->log('site.repositories.updated');
    }

    /** @return list<array{url: string, label: string|null}> */
    private function current(Site $site): array
    {
        return array_values($site->repositories()->get()->map(static fn (SiteRepository $repository): array => ['url' => $repository->url, 'label' => $repository->label])->all());
    }
}
