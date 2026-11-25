import type { ReactNode } from 'react';
import { InfoTooltip } from '@/components/info-tooltip';
import { cn } from '@/lib/utils';

export function EvidenceFacts({
    rows,
    stacked = false,
}: {
    rows: {
        label: string;
        /** Explains the label in an info button beside it. */
        hint?: string;
        value: ReactNode;
        mono?: boolean;
        failed?: boolean;
    }[];
    stacked?: boolean;
}) {
    return (
        <dl
            className={cn(
                'grid gap-x-5 gap-y-1 sm:gap-y-2.5',
                !stacked &&
                    'sm:grid-cols-[minmax(7.5rem,max-content)_minmax(0,1fr)]',
            )}
        >
            {rows.map((row) => (
                <div key={row.label} className="contents">
                    <dt className="pt-px text-xs text-foreground-faint">
                        {row.label}
                        {row.hint && <InfoTooltip>{row.hint}</InfoTooltip>}
                    </dt>
                    <dd
                        className={cn(
                            'mb-2.5 min-w-0 text-cell [overflow-wrap:anywhere]',
                            !stacked && 'sm:mb-0',
                            row.mono && 'font-mono text-xs tabular-nums',
                            row.failed && 'text-status-down',
                        )}
                    >
                        {row.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
