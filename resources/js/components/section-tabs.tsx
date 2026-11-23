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
    const link = (item: SectionTab) => (
        <Link
            key={item.value}
            href={item.href}
            aria-current={item.value === value ? 'page' : undefined}
            prefetch
            className={cn(
                horizontalNavigationItemClass,
                'focus-ring-inset transition-[color] duration-(--motion-press) ease-out hover:text-foreground',
                item.value === value && horizontalNavigationActiveClass,
            )}
        >
            {item.label}
        </Link>
    );
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
                {items.map(link)}
            </div>
        </nav>
    );
}
