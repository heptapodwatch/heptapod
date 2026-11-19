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

/**
 * The one footer below a list: the plural-aware count on the left ("18 sites", "5 runs on this page"), the pager on the
 * right once there is more than one page. Page-number pagination passes previous and next (null = disabled) and shows
 * "Page 1 of 3"; cursor pagination passes only next. Callers render it only for a non-empty list (the EmptyState speaks).
 * A paged major list passes `pageSize`: the "Rows 10 · 20 · 50 · 100" choice, which reloads from the first page and
 * which the server remembers per list. Below 640 px the count and Rows share the first line, the pager the second.
 */
export function ListFooter({
    count,
    previous,
    next,
    page,
    lastPage,
    extra,
    pageSize,
}: {
    count: ReactNode;
    /** Omitted: the list has no previous page concept (cursor pagination). */
    previous?: PageLink;
    next?: PageLink;
    page?: number;
    lastPage?: number;
    /** A list action beside the pager (Runs: Show newest runs). */
    extra?: ReactNode;
    /** The list's current rows per page; shows the Rows choice. */
    pageSize?: number;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const link = (target: PageLink, label: string) =>
        target === null ? (
            <Button variant="outline" disabled>
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
    const paged = (previous ?? null) !== null || (next ?? null) !== null;
    const changePageSize = (size: number) => {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', String(size));
        // A new size starts the list over: page numbers and cursors of the old size point elsewhere.
        for (const cursor of ['page', 'before', 'after'])
            url.searchParams.delete(cursor);
        router.visit(url.pathname + url.search, { preserveScroll: true });
    };
    return (
        <div
            data-list-footer
            className="flex flex-col gap-2 text-xs text-muted-foreground sm:flex-row sm:flex-wrap sm:items-center sm:gap-3"
        >
            <div className="flex items-center justify-between gap-3 sm:contents">
                <span className="font-mono tabular-nums">{count}</span>
                {pageSize !== undefined && (
                    <span className="flex items-center gap-2 sm:ml-auto">
                        <span>{t('Rows')}</span>
                        <SegmentedControl
                            label={t('Rows per page')}
                            value={String(pageSize)}
                            onValueChange={(value) =>
                                changePageSize(Number(value))
                            }
                            options={pageSizes.map((size) => ({
                                value: String(size),
                                label: format.number(size),
                            }))}
                        />
                    </span>
                )}
            </div>
            {(paged || extra) && (
                <div
                    className={
                        pageSize === undefined
                            ? 'flex flex-wrap items-center justify-between gap-2 sm:ml-auto sm:justify-end'
                            : 'flex flex-wrap items-center justify-between gap-2 sm:justify-end'
                    }
                >
                    {extra}
                    {paged &&
                        previous !== undefined &&
                        link(previous, 'Previous page')}
                    {paged && page !== undefined && lastPage !== undefined && (
                        <span className="font-mono tabular-nums">
                            {t('Page {{page}} of {{lastPage}}', {
                                page: format.number(page),
                                lastPage: format.number(lastPage),
                            })}
                        </span>
                    )}
                    {paged && next !== undefined && link(next, 'Next page')}
                </div>
            )}
        </div>
    );
}
