import { Link, router } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import { linkTextClass } from '@/components/text-link';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { TruncatedText } from '@/components/truncated-text';
import { focusRingClass, rowHoverClass } from '@/lib/interaction';
import { cn } from '@/lib/utils';

/**
 * A row or card whose whole area is the target. A table row opens through rowTargetClick and its name link stays the one
 * keyboard target; a card that is itself the link takes the focus ring, drawn inset because bordered lists clip outer
 * rings. Row hover gives the name the same link state as hovering the name itself.
 */
export const rowTargetClass = cn(
    'group/row cursor-pointer focus-visible:ring-inset',
    rowHoverClass,
    focusRingClass,
);

/** Click handler for a row target: plain primary clicks outside other controls and text selections run open. */
export function rowTargetClick(open: () => void) {
    return (event: MouseEvent<HTMLElement>) => {
        if (
            event.button !== 0 ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey ||
            window.getSelection()?.toString() ||
            (event.target instanceof Element &&
                event.target.closest('a,button,input,[role="checkbox"]'))
        )
            return;
        open();
    };
}

export function openRowTarget(href: string) {
    return rowTargetClick(() => router.visit(href));
}

/**
 * Entity name (site, client) with its avatar. The avatar shares the click target but is never underlined.
 * The name stays on one line (truncated with an ellipsis); the shared tooltip shows the full name while it is cut off.
 */
export function EntityLink({
    name,
    icon,
    href,
    tooltip,
    className,
    ariaLabel,
    insideLink = false,
}: {
    name: string;
    /** The enclosing card or row is itself the link: render the link look without a nested link. */
    insideLink?: boolean;
    icon?: ReactNode;
    /** Without href the name renders as plain, focusable text (no link look). */
    href?: string;
    tooltip?: ReactNode;
    className?: string;
    ariaLabel?: string;
}) {
    const outer = cn(
        'group/link inline-flex max-w-full min-w-0 items-center gap-2 rounded-sm text-sm font-medium',
        focusRingClass,
        className,
    );
    const label = (
        <span
            data-entity-name
            data-truncate
            className={cn(
                'min-w-0 truncate',
                (href || insideLink) &&
                    cn(
                        linkTextClass.primary,
                        'group-hover/row:no-underline group-active/row:underline',
                    ),
            )}
        >
            {name}
        </span>
    );
    const element = href ? (
        <Link href={href} prefetch aria-label={ariaLabel} className={outer}>
            {icon}
            {label}
        </Link>
    ) : (
        <span
            tabIndex={tooltip && !insideLink ? 0 : undefined}
            className={outer}
        >
            {icon}
            {label}
        </span>
    );
    if (!tooltip) {
        return <TruncatedText content={name}>{element}</TruncatedText>;
    }
    return (
        <Tooltip>
            <TooltipTrigger asChild>{element}</TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}
