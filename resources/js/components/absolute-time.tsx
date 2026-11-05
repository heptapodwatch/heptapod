import type { ComponentProps } from 'react';
import { TooltipHint } from '@/components/truncated-text';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';

export function AbsoluteTime({
    value,
    timezone,
    mode = 'timestamp',
    label,
    className,
    ...props
}: Omit<ComponentProps<'time'>, 'children' | 'dateTime' | 'title'> & {
    value: string;
    /** Names the exact time in a list row's combined keyboard tooltip (the column header, e.g. "Started"). */
    label?: string;
    timezone?: string;
    mode?:
        | 'timestamp'
        | 'calendarDate'
        | 'time'
        | 'eventTime'
        | 'eventTimestamp';
}) {
    const format = useFormat();

    return (
        <TooltipHint content={format.exact(value, timezone)} label={label}>
            <time
                {...props}
                dateTime={value}
                className={cn('font-mono tabular-nums', className)}
            >
                {format[mode](value, timezone)}
            </time>
        </TooltipHint>
    );
}
