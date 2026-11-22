import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The filtered variant of a list's empty state: shown while any filter differs from its default. Otherwise the first-run
 * copy and the page's create action show. The FilterBar owns the screen's one Clear filters; the description points to it.
 */
export type EmptyStateFiltered = {
    active: boolean;
    title: string;
    description: string;
};

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    filtered,
    bare = false,
}: {
    icon: LucideIcon;
    /** First-run copy: what this list holds. */
    title: string;
    description: string;
    /** First-run action: the page's create action, when the user may create. */
    action?: ReactNode;
    filtered?: EmptyStateFiltered;
    bare?: boolean;
}) {
    const isFiltered = filtered?.active === true;
    return (
        <div
            data-slot="empty"
            className={cn(
                'flex min-h-60 min-w-0 flex-1 flex-col items-center justify-center gap-6 rounded-lg p-6 text-center text-balance md:p-12',
                !bare && 'border border-dashed',
            )}
        >
            <div
                data-slot="empty-header"
                className="flex max-w-sm flex-col items-center gap-2 text-center"
            >
                <div
                    data-slot="empty-icon"
                    data-variant="icon"
                    className="mb-2 flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-6"
                >
                    <Icon aria-hidden="true" />
                </div>
                <div data-slot="empty-title" className="text-section">
                    {isFiltered ? filtered.title : title}
                </div>
                <div
                    data-slot="empty-description"
                    className="text-sm/relaxed text-muted-foreground"
                >
                    {isFiltered ? filtered.description : description}
                </div>
            </div>
            {!isFiltered && action && (
                <div
                    data-slot="empty-content"
                    className="flex w-full max-w-sm min-w-0 flex-col items-center gap-4 text-sm text-balance"
                >
                    {action}
                </div>
            )}
        </div>
    );
}
