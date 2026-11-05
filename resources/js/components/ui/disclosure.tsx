import { ChevronRight } from 'lucide-react';
import { Collapsible as CollapsiblePrimitive } from 'radix-ui';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The one show/hide pattern: a trigger with a 16 px chevron that turns 90° when open (150 ms, static under reduced motion),
 * `aria-expanded` on the trigger and the content mounted only while open.
 */
export function Disclosure({
    summary,
    children,
    className,
    triggerClassName,
    contentClassName,
    chevron = 'start',
    ...props
}: Omit<ComponentProps<typeof CollapsiblePrimitive.Root>, 'children'> & {
    summary: ReactNode;
    children: ReactNode;
    triggerClassName?: string;
    contentClassName?: string;
    /** `end` for full-width row triggers whose leading column must stay aligned with sibling rows. */
    chevron?: 'start' | 'end';
}) {
    const chevronIcon = (
        <ChevronRight
            aria-hidden
            className="size-4 shrink-0 text-muted-foreground transition-transform duration-(--motion-enter) ease-out group-data-[state=open]/disclosure:rotate-90"
        />
    );
    return (
        <CollapsiblePrimitive.Root
            data-slot="disclosure"
            className={className}
            {...props}
        >
            <CollapsiblePrimitive.Trigger
                className={cn(
                    'group/disclosure flex w-full min-w-0 cursor-pointer items-center gap-1.5 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    triggerClassName,
                )}
            >
                {chevron === 'start' && chevronIcon}
                <span className="min-w-0 flex-1">{summary}</span>
                {chevron === 'end' && chevronIcon}
            </CollapsiblePrimitive.Trigger>
            <CollapsiblePrimitive.Content className={contentClassName}>
                {children}
            </CollapsiblePrimitive.Content>
        </CollapsiblePrimitive.Root>
    );
}
