import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';

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
        <Empty className={bare ? 'min-h-60' : 'min-h-60 border'}>
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Icon aria-hidden="true" />
                </EmptyMedia>
                <EmptyTitle>{isFiltered ? filtered.title : title}</EmptyTitle>
                <EmptyDescription>
                    {isFiltered ? filtered.description : description}
                </EmptyDescription>
            </EmptyHeader>
            {!isFiltered && action && <EmptyContent>{action}</EmptyContent>}
        </Empty>
    );
}
