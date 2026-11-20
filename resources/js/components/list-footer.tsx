import { Link, router } from '@inertiajs/react';
import { SegmentedControl } from '@/components/segmented-control';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/hooks/use-format';

/** A pager target: an Inertia link (optionally a partial reload that keeps page state), or null when there is no such page. */
type PageLink = {
    href: string;
    only?: string[];
    preserveState?: boolean;
} | null;

/** The rows-per-page choices; the server accepts exactly these (ListPageSize). */
const pageSizes = [10, 20, 50, 100] as const;

/** Builds a PaginationData view for an in-memory list (an unsaved import plan) so it uses the same ListFooter. */
export function clientPagination(
    currentPage: number,
    perPage: number,
    total: number,
): Heptapod.Contracts.PaginationData {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const page = Math.min(Math.max(1, currentPage), lastPage);
    return {
        current_page: page,
        per_page: perPage,
        total,
        last_page: lastPage,
        next_page_url: page < lastPage ? '#next' : null,
        previous_page_url: page > 1 ? '#previous' : null,
        page_name: 'page',
    };
}

/** Saves a page-size choice for a client-paged list into the same plain `list_page_sizes` cookie the server reads. */
export function rememberListPageSize(list: string, size: number): void {
    const raw = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('list_page_sizes='))
        ?.slice('list_page_sizes='.length);
    let parsed: Record<string, unknown> = {};
    if (raw) {
        try {
            const decoded: unknown = JSON.parse(decodeURIComponent(raw));
            if (
                decoded &&
                typeof decoded === 'object' &&
                !Array.isArray(decoded)
            ) {
                parsed = decoded as Record<string, unknown>;
            }
        } catch {
            // Malformed cookie falls back to a fresh map, matching ListPageSize::remembered.
        }
    }
    parsed[list] = size;
    document.cookie = `list_page_sizes=${encodeURIComponent(JSON.stringify(parsed))};path=/;max-age=31536000;SameSite=Lax`;
}

/**
 * The one footer below a list: the plural-aware count on the left ("18 sites"), or "1–50 of 64" once the list has more than
 * one page; the pager on the right. A page-number list passes its server `pagination` (Previous · Page 1 of 3 · Next and the
 * "Rows 10 · 20 · 50 · 100" choice, which reloads from the first page and which the server remembers per list); a cursor list
 * (Runs history) passes only `next` and its `pageSize`. A list that shares its page with others passes `only`, the props its
 * pager reloads. Callers render it only for a non-empty list (the EmptyState speaks). Below 640 px the count and Rows share
 * the first line, the pager the second.
 */
export function ListFooter({
    count,
    pagination,
    only,
    next,
    previous,
    extra,
    pageSize,
    onPageChange,
    onPageSizeChange,
}: {
    /** The whole list's count, shown while it fits one page. */
    count: ReactNode;
    pagination?: Heptapod.Contracts.PaginationData;
    /** The props a page or size change reloads, for a list that shares its page with others. */
    only?: string[];
    /** Lists without a total (Runs history, Upcoming): the next page (null = disabled), and the previous one where it exists. */
    next?: PageLink;
    previous?: PageLink;
    /** A list action beside the pager (Runs: Show newest runs). */
    extra?: ReactNode;
    /** Cursor lists only: the current rows per page; shows the Rows choice. */
    pageSize?: number;
    /** In-memory lists (unsaved import plan): change page without leaving the unsaved state. */
    onPageChange?: (page: number) => void;
    /** In-memory lists: change page size without leaving the unsaved state. */
    onPageSizeChange?: (size: number) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const pageLink = (href: string | null): PageLink =>
        href === null
            ? null
            : { href, only, preserveState: only !== undefined || undefined };
    const link = (target: PageLink, label: string, targetPage?: number) =>
        target === null ? (
            <Button type="button" variant="outline" disabled>
                {t(label)}
            </Button>
        ) : onPageChange && targetPage !== undefined ? (
            <Button
                type="button"
                variant="outline"
                onClick={() => onPageChange(targetPage)}
            >
                {t(label)}
            </Button>
        ) : (
            <Button variant="outline" asChild>
                <Link
                    href={target.href}
                    only={target.only}
                    preserveState={target.preserveState}
                    preserveScroll
                    prefetch
                >
                    {t(label)}
                </Link>
            </Button>
        );
    const previousPage = pagination
        ? pageLink(pagination.previous_page_url)
        : previous;
    const nextPage = pagination ? pageLink(pagination.next_page_url) : next;
    const paged =
        (previousPage ?? null) !== null || (nextPage ?? null) !== null;
    // Rows only where a size can change what is shown: a list of 10 or fewer rows shows them all at every size.
    const size = pagination
        ? pagination.total > pageSizes[0]
            ? pagination.per_page
            : undefined
        : pageSize;
    const pageName = pagination?.page_name ?? 'page';
    const changePageSize = (chosen: number) => {
        if (onPageSizeChange) {
            onPageSizeChange(chosen);
            return;
        }
        const url = new URL(window.location.href);
        url.searchParams.set(
            pageName.replace(/page$/, 'per_page'),
            String(chosen),
        );
        // A new size starts the list over: page numbers and cursors of the old size point elsewhere.
        for (const cursor of [pageName, 'before', 'after'])
            url.searchParams.delete(cursor);
        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            only,
            preserveState: only !== undefined || undefined,
        });
    };
    return (
        <div
            data-list-footer
            className="flex flex-col gap-2 text-xs text-muted-foreground sm:flex-row sm:flex-wrap sm:items-center sm:gap-3"
        >
            <div className="flex items-center justify-between gap-3 sm:contents">
                <span className="font-mono tabular-nums">
                    {pagination && pagination.last_page > 1
                        ? t('{{from}}–{{to}} of {{total}}', {
                              from: format.number(
                                  (pagination.current_page - 1) *
                                      pagination.per_page +
                                      1,
                              ),
                              to: format.number(
                                  Math.min(
                                      pagination.current_page *
                                          pagination.per_page,
                                      pagination.total,
                                  ),
                              ),
                              total: format.number(pagination.total),
                          })
                        : count}
                </span>
                {size !== undefined && (
                    <span className="flex items-center gap-2 sm:ml-auto">
                        <span>{t('Rows')}</span>
                        <SegmentedControl
                            label={t('Rows per page')}
                            value={String(size)}
                            onValueChange={(value) =>
                                changePageSize(Number(value))
                            }
                            options={pageSizes.map((choice) => ({
                                value: String(choice),
                                label: format.number(choice),
                            }))}
                        />
                    </span>
                )}
            </div>
            {(paged || extra) && (
                <div
                    className={
                        size === undefined
                            ? 'flex flex-wrap items-center justify-between gap-2 sm:ml-auto sm:justify-end'
                            : 'flex flex-wrap items-center justify-between gap-2 sm:justify-end'
                    }
                >
                    {extra}
                    {paged &&
                        previousPage !== undefined &&
                        link(
                            previousPage,
                            'Previous page',
                            pagination
                                ? pagination.current_page - 1
                                : undefined,
                        )}
                    {paged && pagination && (
                        <span className="font-mono tabular-nums">
                            {t('Page {{page}} of {{lastPage}}', {
                                page: format.number(pagination.current_page),
                                lastPage: format.number(pagination.last_page),
                            })}
                        </span>
                    )}
                    {paged &&
                        nextPage !== undefined &&
                        link(
                            nextPage,
                            'Next page',
                            pagination
                                ? pagination.current_page + 1
                                : undefined,
                        )}
                </div>
            )}
        </div>
    );
}
