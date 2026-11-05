import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function EvidenceFacts({
    rows,
    stacked = false,
}: {
    rows: {
        label: string;
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
                    <dt className="pt-px text-xs text-[var(--foreground-faint)]">
                        {row.label}
                    </dt>
                    <dd
                        className={cn(
                            'mb-2.5 min-w-0 text-cell [overflow-wrap:anywhere]',
                            !stacked && 'sm:mb-0',
                            row.mono && 'font-mono text-xs tabular-nums',
                            row.failed && 'text-[var(--status-down)]',
                        )}
                    >
                        {row.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
