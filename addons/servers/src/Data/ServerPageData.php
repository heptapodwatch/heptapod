<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Contracts\DirectorySiteData;
use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelData\Support\Lazy\InertiaLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Props of GET /servers/{id}. Server, history and the linked sites are closures, so the page's polls evaluate only what
 * they ask for; link (the DNS suggestions and the sites to pick from) is read only when the "Link site…" sheet asks for it.
 */
#[TypeScript]
final class ServerPageData extends Data
{
    /**
     * History: the peak per step over the retention window. Install: the agent comes from a GitHub release, never from
     * the instance; version is that release's tag, or null when no release is known.
     *
     * @param  ClosureLazy|array{step_seconds: int, points: list<array{at: string, cpu: float, load: float, memory: float|null, disks: array<string, float>}>}  $history
     * @param  array{endpoint: string, installed_version: string, version: string|null, release_url: string|null}  $install
     * @param  ClosureLazy|list<DirectorySiteData>  $linked_sites  one page of the visible ones, problem sites first
     */
    public function __construct(
        public readonly ClosureLazy|ServerRowData $server,
        public readonly ServerThresholdsData $defaults,
        public readonly ClosureLazy|array $history,
        public readonly array $install,
        public readonly ClosureLazy|array $linked_sites,
        public readonly ClosureLazy|PaginationData $linked_sites_pagination,
        public readonly InertiaLazy|ServerLinkData|null $link = null,
    ) {}
}
