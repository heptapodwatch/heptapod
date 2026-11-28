import type { ComponentProps } from 'react';
import { TooltipHint } from '@/components/truncated-text';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';

type TimeMode =
    | { mode: 'listTime'; end?: string; seconds?: boolean }
    | {
          mode?:
              | 'timestamp'
              | 'calendarDate'
              | 'time'
              | 'eventTime'
              | 'eventTimestamp';
          end?: never;
          seconds?: never;
      };

export function AbsoluteTime({
    value,
    timezone,
    mode = 'timestamp',
    end,
    seconds,
    label,
    className,
    ...props
}: Omit<ComponentProps<'time'>, 'children' | 'dateTime' | 'title'> & {
    value: string;
    /** Names the exact time in a list row's combined keyboard tooltip (the column header, e.g. "Started"). */
    label?: string;
    timezone?: string;
} & TimeMode) {
    const format = useFormat();
    const text =
        mode === 'listTime'
            ? format.listTime(value, {
                  end,
                  seconds,
                  displayTimezone: timezone,
              })
            : format[mode](value, timezone);
    const isRange = end !== undefined && Date.parse(value) !== Date.parse(end);
    const exact = format.exact(value, timezone);
    const styles = cn('font-mono tabular-nums', className);

    return (
        <TooltipHint
            content={
                isRange ? `${exact} – ${format.exact(end, timezone)}` : exact
            }
            label={label}
        >
            {isRange ? (
                <span {...props} className={styles}>
                    {text}
                </span>
            ) : (
                <time {...props} dateTime={value} className={styles}>
                    {text}
                </time>
            )}
        </TooltipHint>
    );
}
