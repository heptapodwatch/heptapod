import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { HeptapodMark } from '@/components/heptapod-mark';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** Matches the longest recovered keyframe run in heptapod-mark.css (2020 ms + the 180 ms wash delay). */
const recoveredMs = 2200;

export type BrandState = {
    downCount: number;
    recovered: boolean;
    /** Null during the recovered one-shot: it only confirms, there is nothing to open. */
    href: string | null;
    label: string;
};

/** The Heptapod lockup: the live mark and the outlined lowercase wordmark. Size follows `--brand-mark-h`. */
export default function AppLogo({
    brand,
    markOnly = false,
}: {
    brand?: BrandState;
    markOnly?: boolean;
}) {
    return (
        <span className="hp-brand" role="img" aria-label="Heptapod">
            <HeptapodMark
                downCount={brand?.downCount ?? 0}
                recovered={brand?.recovered ?? false}
            />
            {!markOnly && <span className="hp-word" />}
        </span>
    );
}

/**
 * The shell's brand link (sidebar, mobile bar). Accepts the props a Slot (`asChild`) passes on.
 * During the recovered one-shot it is not a link.
 */
export function BrandLink({
    markOnly = false,
    className,
    ...props
}: Omit<ComponentProps<typeof Link>, 'href'> & { markOnly?: boolean }) {
    const brand = useBrandState();
    const { t } = useTranslation();
    const label = `Heptapod · ${brand.label}`;
    const content = <AppLogo brand={brand} markOnly={markOnly} />;
    const trigger =
        brand.href === null ? (
            <span
                {...(props as unknown as ComponentProps<'span'>)}
                role="img"
                aria-label={label}
                tabIndex={0}
                className={cn('hp-brand-link', className)}
            >
                {content}
            </span>
        ) : (
            <Link
                {...props}
                href={brand.href}
                prefetch
                aria-label={label}
                className={cn('hp-brand-link', className)}
            >
                {content}
            </Link>
        );
    return (
        <Tooltip>
            <TooltipTrigger asChild>{trigger}</TooltipTrigger>
            <TooltipContent side="right" align="start">
                <TooltipDetails
                    subject="Heptapod"
                    status={{
                        state: brand.downCount > 0 ? 'down' : 'ok',
                        label:
                            brand.downCount > 0
                                ? t('brand.down_sites', {
                                      count: brand.downCount,
                                  })
                                : t('All sites reachable'),
                    }}
                    hint={brand.href === null ? undefined : t('View sites')}
                />
            </TooltipContent>
        </Tooltip>
    );
}

/** The shell brand: the Down count from the shared health prop, the recovered one-shot and the link target. */
export function useBrandState(): BrandState {
    const { t } = useTranslation();
    const downCount = usePage().props.overallHealth?.down_site_count ?? 0;
    const recovered = useRecovered(downCount);
    if (downCount > 0) {
        return {
            downCount,
            recovered,
            href: '/sites?filter[state]=down',
            label: t('{{count}} sites down: show them', { count: downCount }),
        };
    }
    return {
        downCount,
        recovered,
        href: recovered ? null : '/sites',
        label: t('All sites reachable'),
    };
}

/**
 * True for one recovered one-shot after the Down count falls to zero; never on first render.
 * Skipped under reduced motion and in a hidden tab; hiding the tab mid-shot applies the final state at once.
 */
function useRecovered(downCount: number): boolean {
    const previous = useRef(downCount);
    const [recovered, setRecovered] = useState(false);
    useEffect(() => {
        const wasDown = previous.current > 0;
        previous.current = downCount;
        if (downCount > 0) {
            setRecovered(false);
            return;
        }
        if (
            !wasDown ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }
        setRecovered(true);
        const finish = () => setRecovered(false);
        const timer = window.setTimeout(finish, recoveredMs);
        const onHidden = () => {
            if (document.hidden) {
                window.clearTimeout(timer);
                finish();
            }
        };
        document.addEventListener('visibilitychange', onHidden);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onHidden);
        };
    }, [downCount]);
    return recovered;
}
