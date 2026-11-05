import type { ReactNode } from 'react';
import { SectionHeading } from '@/components/section-heading';
import { cn } from '@/lib/utils';

/**
 * One KPI: a section-token label, a mono tabular value (stat token) and optional muted meta lines. `bordered` gives the
 * standalone tile its own panel; inside an existing card or form section the tile stays bare.
 */
export function StatTile({
    label,
    value,
    meta,
    bordered = false,
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    meta?: ReactNode;
    bordered?: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1.5',
                bordered && 'rounded-lg border bg-background p-4',
                className,
            )}
        >
            <SectionHeading>{label}</SectionHeading>
            <div className="font-mono text-stat break-words tabular-nums">
                {value}
            </div>
            {meta !== undefined && (
                <div className="text-xs leading-relaxed text-muted-foreground">
                    {meta}
                </div>
            )}
        </div>
    );
}
