import { Link } from '@inertiajs/react';
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

/**
 * The one footer below a list: the plural-aware count on the left ("18 sites", "5 runs on this page"), the pager on the
 * right once there is more than one page. Page-number pagination passes previous and next (null = disabled) and shows
 * "Page 1 of 3"; cursor pagination passes only next. Callers render it only for a non-empty list (the EmptyState speaks).
 */
export function ListFooter({
    count,
    previous,
    next,
    page,
    lastPage,
    extra,
}: {
    count: ReactNode;
    /** Omitted: the list has no previous page concept (cursor pagination). */
    previous?: PageLink;
    next?: PageLink;
    page?: number;
    lastPage?: number;
    /** A list action beside the pager (Runs: Show newest runs). */
    extra?: ReactNode;
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
    return (
        <div
            data-list-footer
            className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
        >
            <span className="font-mono tabular-nums">{count}</span>
            {(paged || extra) && (
                <div className="flex flex-wrap items-center gap-2">
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
