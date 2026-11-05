import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    horizontalNavigationClass,
    horizontalNavigationItemClass,
    horizontalNavigationActiveClass,
    horizontalNavigationFadeClass,
} from '@/lib/horizontal-navigation';
import { cn } from '@/lib/utils';

export type SectionTab = {
    value: string;
    label: ReactNode;
    href: string;
    muted?: boolean;
    order?: number;
    separated?: boolean;
};

export function SectionTabs({
    items,
    value,
    label,
    className,
}: {
    items: SectionTab[];
    value: string;
    label: string;
    className?: string;
}) {
    const navigation = useRef<HTMLElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const [overflow, setOverflow] = useState({ start: false, end: false });
    useEffect(() => {
        // Centre the active tab so the items before it stay visible instead of being cut at the edge.
        navigation.current
            ?.querySelector('[aria-current="page"]')
            ?.scrollIntoView({
                block: 'nearest',
                inline: 'center',
            });
    }, [value]);
    useEffect(() => {
        const row = scroller.current;
        if (!row) return;
        const measure = () =>
            setOverflow({
                start: row.scrollLeft > 0,
                end: row.scrollLeft + row.clientWidth < row.scrollWidth - 1,
            });
        measure();
        row.addEventListener('scroll', measure, { passive: true });
        const resize = new ResizeObserver(measure);
        resize.observe(row);
        return () => {
            row.removeEventListener('scroll', measure);
            resize.disconnect();
        };
    }, []);
    const orderedItems = items.toSorted(
        (a, b) => (a.order ?? 0) - (b.order ?? 0),
    );
    const link = (item: SectionTab) => (
        <Link
            key={item.value}
            href={item.href}
            aria-current={item.value === value ? 'page' : undefined}
            prefetch
            data-muted={item.muted || undefined}
            className={cn(
                horizontalNavigationItemClass,
                'hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset',
                item.value === value && horizontalNavigationActiveClass,
                item.muted && 'text-muted-foreground',
            )}
        >
            {item.label}
        </Link>
    );
    // Separated items (site Settings) stay pinned at the right, outside the scroll area, so they are never cut off.
    const pinned = orderedItems.filter((item) => item.separated);
    return (
        <nav
            ref={navigation}
            aria-label={label}
            data-section-tabs
            className={cn('flex max-w-full border-b', className)}
            onKeyDown={(event) => {
                if (
                    !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(
                        event.key,
                    )
                )
                    return;
                const links = Array.from(
                    event.currentTarget.querySelectorAll('a'),
                );
                const index = links.indexOf(event.target as HTMLAnchorElement);
                if (index < 0) return;
                event.preventDefault();
                const next =
                    event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                          ? links.length - 1
                          : (index +
                                (event.key === 'ArrowRight' ? 1 : -1) +
                                links.length) %
                            links.length;
                links[next]!.focus();
                links[next]!.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                });
            }}
        >
            <div
                ref={scroller}
                data-section-tabs-scroll
                data-overflow-start={overflow.start || undefined}
                data-overflow-end={overflow.end || undefined}
                className={cn(
                    horizontalNavigationClass,
                    horizontalNavigationFadeClass,
                    'min-w-0 flex-1 border-b-0',
                )}
            >
                {orderedItems.filter((item) => !item.separated).map(link)}
            </div>
            {pinned.length > 0 && (
                <div className="flex shrink-0 gap-4 pl-4">
                    <span
                        role="separator"
                        aria-orientation="vertical"
                        aria-hidden
                        className="h-4 shrink-0 self-center border-l border-border"
                    />
                    {pinned.map(link)}
                </div>
            )}
        </nav>
    );
}
