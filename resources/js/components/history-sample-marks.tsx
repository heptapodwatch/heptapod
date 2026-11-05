import { useRef, useState, type SVGProps } from 'react';
import type { TFunction } from 'i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
    type TooltipDetailsProps,
} from '@/components/ui/tooltip';
import { useFormat } from '@/hooks/use-format';
import { useTranslation } from 'react-i18next';
import { ReferenceLine } from 'recharts';
import { statusLabels } from '@/lib/status';

type Sample = {
    time: string;
    failure_count: number;
    error_count: number;
    warning_count: number;
    failure_status?: Heptapod.Enums.BucketStatus | null;
};

export function historySampleStatus(
    points: readonly Sample[],
    t: TFunction,
    failureState: 'down' | 'failed' = 'down',
    scope: 'bucket' | 'range' = 'bucket',
): TooltipDetailsProps['status'] {
    const counts = points.reduce(
        (total, point) => ({
            failure: total.failure + point.failure_count,
            error: total.error + point.error_count,
            warning: total.warning + point.warning_count,
        }),
        { failure: 0, error: 0, warning: 0 },
    );
    const summary = [
        counts.failure > 0 &&
            t(
                scope === 'bucket'
                    ? 'sparkline.bucket_failures'
                    : 'sparkline.failures',
                { count: counts.failure },
            ),
        counts.error > 0 &&
            t(
                scope === 'bucket'
                    ? 'sparkline.bucket_errors'
                    : 'sparkline.errors',
                { count: counts.error },
            ),
        counts.warning > 0 &&
            t(
                scope === 'bucket'
                    ? 'sparkline.bucket_warnings'
                    : 'sparkline.warnings',
                { count: counts.warning },
            ),
    ]
        .filter(Boolean)
        .join(' · ');
    const failed = points.some(
        (point) =>
            point.failure_status === 'failed' ||
            (point.failure_count > 0 && point.failure_status == null),
    );
    const interrupted = points.some(
        (point) => point.failure_status === 'interrupted',
    );
    if (!summary && !failed && !interrupted) return undefined;
    const state = failed
        ? failureState
        : counts.error > 0
          ? 'check-error'
          : 'warning';
    const label = t(statusLabels[state]);
    return { state, label: [label, summary].filter(Boolean).join(' · ') };
}

export function useHistorySampleSummary(points: Sample[]) {
    const { t } = useTranslation();
    return historySampleStatus(points, t, 'down', 'range')?.label ?? '';
}

function SampleMarkLine({
    x1,
    y1,
    x2,
    y2,
    stroke,
    strokeWidth,
    summary,
    time,
    state,
    ...props
}: SVGProps<SVGLineElement> & {
    summary: string;
    time: string;
    state: 'down' | 'check-error' | 'warning';
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const timestamp = format.exact(time);
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <line
                    {...props}
                    role="img"
                    aria-label={`${timestamp} · ${summary}`}
                    x1={x1}
                    y1={y1}
                    x2={x2}
                    y2={y2}
                    stroke={stroke}
                    strokeWidth={strokeWidth}
                    className="recharts-reference-line-line"
                ></line>
            </TooltipTrigger>
            <TooltipContent side="right">
                <TooltipDetails
                    subject={t('Response time')}
                    facts={[timestamp]}
                    status={{
                        state,
                        label: `${t(statusLabels[state])} · ${summary}`,
                    }}
                    hint={t('Use arrow keys to inspect history.')}
                />
            </TooltipContent>
        </Tooltip>
    );
}

export function HistorySampleMarks({ points }: { points: Sample[] }) {
    const { t } = useTranslation();
    const [focused, setFocused] = useState(0);
    const triggers = useRef<(SVGLineElement | null)[]>([]);
    const kinds = [
        {
            count: 'failure_count',
            state: 'down',
            color: 'var(--status-down)',
            width: 3,
            label: 'sparkline.bucket_failures',
        },
        {
            count: 'error_count',
            state: 'check-error',
            color: 'var(--status-error)',
            width: 1.5,
            label: 'sparkline.bucket_errors',
        },
        {
            count: 'warning_count',
            state: 'warning',
            color: 'var(--status-warn)',
            width: 1,
            label: 'sparkline.bucket_warnings',
        },
    ] as const;
    const marks = points.flatMap((point) =>
        kinds
            .filter((kind) => point[kind.count] > 0)
            .map((kind) => ({ point, ...kind })),
    );
    return marks.map((mark, index) => (
        <ReferenceLine
            key={mark.point.time + '-' + mark.count}
            x={mark.point.time}
            stroke={mark.color}
            strokeWidth={mark.width}
            shape={
                <SampleMarkLine
                    ref={(element) => {
                        triggers.current[index] = element;
                    }}
                    tabIndex={
                        index === Math.min(focused, marks.length - 1) ? 0 : -1
                    }
                    onFocus={() => setFocused(index)}
                    onKeyDown={(event) => {
                        if (
                            event.key !== 'ArrowLeft' &&
                            event.key !== 'ArrowRight'
                        )
                            return;
                        event.preventDefault();
                        event.stopPropagation();
                        triggers.current[
                            Math.max(
                                0,
                                Math.min(
                                    marks.length - 1,
                                    index +
                                        (event.key === 'ArrowRight' ? 1 : -1),
                                ),
                            )
                        ]?.focus();
                    }}
                    time={mark.point.time}
                    state={mark.state}
                    summary={t(mark.label, { count: mark.point[mark.count] })}
                />
            }
        />
    ));
}
