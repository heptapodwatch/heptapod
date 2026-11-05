import { EASE_IN_OUT } from '@/lib/motion';
import { useEffect, useLayoutEffect, useRef } from 'react';
import { statusColor } from '@/components/monitoring';
import { stateCountOrder, statusLabels } from '@/lib/status';
import type { SiteState } from '@/types/monitoring';
import { useTranslation } from 'react-i18next';
import { useFormat } from '@/hooks/use-format';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/** Circumference of r = 15.9155 in a 36-unit box: arc lengths read as percent of the portfolio. */
const CIRCUMFERENCE = 100;
const RADIUS = 15.9155;
const RESHARE_MS = 600;

/** The tile column of each site state. */
const tileColumns = {
    down: 'down',
    failed: 'failed',
    'check-error': 'check_errors',
    warning: 'warning',
    pending: 'pending',
    maintenance: 'maintenance',
    paused: 'paused',
    disabled: 'disabled',
    unavailable: 'unavailable',
    ok: 'ok',
} as const satisfies Record<
    SiteState,
    keyof Heptapod.Data.Ui.DashboardTilesData
>;

/** Severity order around the ring, starting at twelve o'clock. */
const segments = stateCountOrder.map(
    (state) => [tileColumns[state], state] as const,
);

type Arc = {
    key: (typeof segments)[number][0];
    state: (typeof segments)[number][1];
    dasharray: string;
    dashoffset: string;
};

function arcs(tiles: Heptapod.Data.Ui.DashboardTilesData): Arc[] {
    const total = segments.reduce((sum, [key]) => sum + Number(tiles[key]), 0);
    let offset = 0;
    return segments.map(([key, state]) => {
        const length =
            total === 0 ? 0 : (Number(tiles[key]) / total) * CIRCUMFERENCE;
        const arc = {
            key,
            state,
            dasharray: `${length} ${CIRCUMFERENCE}`,
            dashoffset: String(-offset),
        };
        offset += length;
        return arc;
    });
}

/**
 * The portfolio's site states as one ring before the Sites headline; focus reveals the full state counts.
 * When the counts change the arcs re-share the circle over 600 ms; reduced motion or a hidden tab jumps.
 */
export function SitesStateRing({
    tiles,
}: {
    tiles: Heptapod.Data.Ui.DashboardTilesData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const populated = segments.filter(([key]) => Number(tiles[key]) > 0);
    const dominant = populated[0];
    const total = segments.reduce((sum, [key]) => sum + Number(tiles[key]), 0);
    const countLabel = ([key, state]: (typeof segments)[number]) =>
        `${t(statusLabels[state])}: ${format.number(Number(tiles[key]))}`;
    const circles = useRef<(SVGCircleElement | null)[]>([]);
    const ring = useRef<HTMLSpanElement>(null);
    // The Down halo is a CSS animation; it pauses while the tab is hidden and resumes on return.
    useEffect(() => {
        const sync = () =>
            ring.current?.toggleAttribute('data-tab-hidden', document.hidden);
        sync();
        document.addEventListener('visibilitychange', sync);
        return () => document.removeEventListener('visibilitychange', sync);
    }, []);
    const shown = useRef<Arc[] | null>(null);
    const next = arcs(tiles);
    useLayoutEffect(() => {
        const before = shown.current;
        shown.current = next;
        if (
            before === null ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        next.forEach((arc, index) => {
            const from = before.find((shown) => shown.key === arc.key);
            const circle = circles.current[index];
            if (
                from === undefined ||
                circle == null ||
                (from.dasharray === arc.dasharray &&
                    from.dashoffset === arc.dashoffset)
            )
                return;
            for (const running of circle.getAnimations()) running.cancel();
            circle.animate(
                {
                    strokeDasharray: [from.dasharray, arc.dasharray],
                    strokeDashoffset: [from.dashoffset, arc.dashoffset],
                },
                { duration: RESHARE_MS, easing: EASE_IN_OUT },
            );
        });
    });
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    ref={ring}
                    role="img"
                    tabIndex={0}
                    aria-label={t('Sites')}
                    data-sites-state-ring
                    data-down={tiles.down > 0 ? '' : undefined}
                    className="sites-state-ring size-7 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 36 36"
                        className="size-full -rotate-90"
                    >
                        <circle
                            cx="18"
                            cy="18"
                            r={RADIUS}
                            fill="none"
                            stroke="var(--muted)"
                            strokeWidth="3.5"
                        />
                        {next.map((arc, index) => (
                            <circle
                                key={arc.key}
                                ref={(element) => {
                                    circles.current[index] = element;
                                }}
                                data-arc={arc.state}
                                cx="18"
                                cy="18"
                                r={RADIUS}
                                fill="none"
                                stroke={statusColor[arc.state]}
                                strokeWidth="3.5"
                                strokeDasharray={arc.dasharray}
                                strokeDashoffset={arc.dashoffset}
                            />
                        ))}
                    </svg>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <TooltipDetails
                    subject={t('Sites')}
                    facts={[
                        t('tooltip.sites', { count: total }),
                        ...populated.slice(1).map(countLabel),
                    ]}
                    status={
                        dominant
                            ? {
                                  state: dominant[1],
                                  label: countLabel(dominant),
                              }
                            : undefined
                    }
                />
            </TooltipContent>
        </Tooltip>
    );
}
