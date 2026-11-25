import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { HeptapodMark, type MarkUpdate } from '@/components/heptapod-mark';
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
    /** Owners and Admins only: a newer release is out. `label` is the full sentence for screen readers; the tooltip
     * shows `headline` under its "Heptapod" title. */
    update: { mark: MarkUpdate; label: string; headline: string } | null;
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
                update={brand?.update?.mark}
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
    tooltipSide = 'right',
    className,
    ...props
}: Omit<ComponentProps<typeof Link>, 'href'> & {
    markOnly?: boolean;
    /** The phone bar has no room to the right of the centred brand. */
    tooltipSide?: 'right' | 'bottom';
}) {
    const brand = useBrandState();
    const { t } = useTranslation();
    const version = usePage().props.heptapodVersion;
    const label = [`Heptapod · ${brand.label}`, brand.update?.label]
        .filter(Boolean)
        .join(' · ');
    const content = <AppLogo brand={brand} markOnly={markOnly} />;
    const trigger =
        brand.href === null ? (
            <span
                {...(props as unknown as ComponentProps<'span'>)}
                role="img"
                aria-label={label}
                tabIndex={0}
                className={cn('hp-brand-link rounded-md focus-ring', className)}
            >
                {content}
            </span>
        ) : (
            <Link
                {...props}
                href={brand.href}
                prefetch
                aria-label={label}
                className={cn('hp-brand-link rounded-md focus-ring', className)}
            >
                {content}
            </Link>
        );
    return (
        <Tooltip>
            <TooltipTrigger asChild>{trigger}</TooltipTrigger>
            <TooltipContent side={tooltipSide} align="start">
                <TooltipDetails
                    subject={
                        <>
                            Heptapod
                            {version && version !== 'unknown' && (
                                // The installed version, for everyone signed in; the update line names only the new one.
                                <span className="ml-2 text-tooltip-muted">
                                    {version}
                                </span>
                            )}
                        </>
                    }
                    facts={
                        brand.update
                            ? [<UpdateNotice key="update" {...brand.update} />]
                            : undefined
                    }
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
    const { overallHealth, appUpdate } = usePage().props;
    const downCount = overallHealth?.down_site_count ?? 0;
    const recovered = useRecovered(downCount);
    const available =
        appUpdate?.status === 'available' && appUpdate.latest !== null;
    const update: BrandState['update'] = available
        ? {
              mark: appUpdate.security ? 'security' : 'update',
              label: t(
                  appUpdate.security
                      ? 'Heptapod :version includes a security fix.'
                      : 'Heptapod :version is available.',
                  { version: appUpdate.latest },
              ),
              headline: t(
                  appUpdate.security
                      ? ':version includes a security fix.'
                      : ':version is available.',
                  { version: appUpdate.latest },
              ),
          }
        : null;
    if (downCount > 0) {
        return {
            update,
            downCount,
            recovered,
            href: '/sites?filter[state]=down',
            label: t('{{count}} sites down: show them', { count: downCount }),
        };
    }
    return {
        update,
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

/**
 * The update line of the mark's tooltip, tied to the lit stem: a stem-shaped marker and the headline in the stem's colour
 * (the tooltip's own status tokens, AA on its dark surface), the explanation muted below.
 */
function UpdateNotice({ mark, headline }: NonNullable<BrandState['update']>) {
    const { t } = useTranslation();
    const security = mark === 'security';
    return (
        <span className="my-0.5 block">
            <span
                className={cn(
                    'flex items-start gap-1.5',
                    security ? 'text-status-down' : 'text-status-warn',
                )}
            >
                <span
                    aria-hidden="true"
                    className={cn(
                        // Upright like the lit stem; centred on the first 17px line when the headline wraps.
                        'mt-1 h-2.5 w-1 shrink-0 rounded-full',
                        security ? 'bg-status-down' : 'bg-status-warn',
                    )}
                />
                {headline}
            </span>
            <span className="block">
                {t(
                    'An update of Heptapod itself, not of a site. Details in Updates & backups.',
                )}
            </span>
        </span>
    );
}
