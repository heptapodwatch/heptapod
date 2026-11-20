<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One page of a paged list, as the shared ListFooter shows it. `page_name` is the list's page parameter; its size parameter is the same name with `per_page` for `page` (`page` → `per_page`, `cron_tasks_page` → `cron_tasks_per_page`). */
#[TypeScript]
final class PaginationData extends Data
{
    public function __construct(
        public readonly int $current_page,
        public readonly int $per_page,
        public readonly int $total,
        public readonly int $last_page,
        public readonly ?string $next_page_url,
        public readonly ?string $previous_page_url,
        public readonly string $page_name = 'page',
    ) {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $page
     */
    public static function fromPaginator(LengthAwarePaginator $page): self
    {
        return new self($page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->nextPageUrl(), $page->previousPageUrl(), $page->getPageName());
    }
}
