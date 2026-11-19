// MOCKUP ONLY (branch feat-sites-visual, phase 1). Remove in phase 2.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { QuietDash, StatusDot, statusColor } from '@/components/monitoring';
import { SegmentedControl } from '@/components/segmented-control';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormat } from '@/hooks/use-format';
import type { SiteDto } from '@/types/monitoring';
import {
    mockupDimensions,
    setMockupVariant,
    useMockupVariant,
    type MockupDimension,
} from '@/lib/sites-visual-variants';

const dimensionLabels: Record<MockupDimension, string> = {
    strip: 'Strip',
    lighthouse: 'Lighthouse',
    head: 'Header',
    incidents: 'Header',
    spark: 'Sparkline',
    cols: 'Columns',
    pagesize: 'Page size',
    activity: 'Timeline',
};

function DimensionControl({ dimension }: { dimension: MockupDimension }) {
    const variant = useMockupVariant(dimension);
    return (
        <div className="flex items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground">
                {dimensionLabels[dimension]}
            </span>
            <SegmentedControl
                label={`${dimensionLabels[dimension]} variant`}
                value={variant}
                onValueChange={(next) =>
                    setMockupVariant(dimension, next as typeof variant)
                }
                options={mockupDimensions[dimension].map((value) => ({
                    value,
                    label:
                        value === 'current' || value === 'off'
                            ? 'Today'
                            : value.toUpperCase(),
                }))}
            />
        </div>
    );
}

const lighthouseCategories = [
    'performance',
    'accessibility',
    'best_practices',
    'seo',
] as const;

/** The score bands of the Lighthouse report: 90+ good, 50–89 needs improvement, below 50 poor. */
function scoreState(score: number): 'ok' | 'warning' | 'failed' {
    return score >= 90 ? 'ok' : score >= 50 ? 'warning' : 'failed';
}

/** A listed site with a Lighthouse check that runs; the column shows only when one exists. */
export function hasLighthouse(site: SiteDto): boolean {
    return site.checks.some(
        (check) =>
            check.key === 'lighthouse' &&
            check.enabled &&
            check.state !== 'unavailable',
    );
}

function PerformanceRing({ score }: { score: number }) {
    const color = statusColor[scoreState(score)];
    return (
        <span
            aria-hidden
            className="grid size-7 place-items-center rounded-full font-mono text-micro leading-none tabular-nums"
            style={{
                background: `conic-gradient(${color} ${score}%, var(--muted) 0)`,
            }}
        >
            <span
                className="grid size-[23px] place-items-center rounded-full bg-card"
                style={{ color }}
            >
                {score}
            </span>
        </span>
    );
}

/** One Lighthouse cell of the Sites list: L1 four strip shapes, L2 four numbers, L3 the performance ring. */
export function LighthouseCell({
    site,
    variant,
}: {
    site: SiteDto;
    variant: 'l1' | 'l2' | 'l3';
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const check = site.checks.find((item) => item.key === 'lighthouse');
    const scores = site.lighthouse;
    const label = t('checks.lighthouse.label');
    if (!hasLighthouse(site) || scores === null) {
        const reason = !hasLighthouse(site)
            ? t('No Lighthouse check')
            : t('No scores yet');
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        role="img"
                        tabIndex={-1}
                        aria-label={`${label} · ${reason}`}
                        className="inline-flex"
                    >
                        <QuietDash />
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    <TooltipDetails
                        subject={label}
                        status={{ state: 'disabled', label: reason }}
                    />
                </TooltipContent>
            </Tooltip>
        );
    }
    const facts = lighthouseCategories.map(
        (category) => `${t(`lighthouse.${category}`)}: ${scores[category]}`,
    );
    const worst = Math.min(...lighthouseCategories.map((c) => scores[c]));
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    role="img"
                    tabIndex={-1}
                    aria-label={`${t('lighthouse.scores')}: ${facts.join(', ')}`}
                    className="inline-flex items-center gap-1.5"
                >
                    {variant === 'l1' &&
                        lighthouseCategories.map((category) => (
                            <span
                                key={category}
                                aria-hidden
                                className="inline-flex"
                            >
                                <StatusDot
                                    state={scoreState(scores[category])}
                                    strip
                                />
                            </span>
                        ))}
                    {variant === 'l2' &&
                        lighthouseCategories.map((category) => (
                            <span
                                key={category}
                                aria-hidden
                                className="w-[3ch] text-right font-mono text-xs tabular-nums"
                                style={{
                                    color:
                                        scoreState(scores[category]) === 'ok'
                                            ? 'var(--muted-foreground)'
                                            : statusColor[
                                                  scoreState(scores[category])
                                              ],
                                }}
                            >
                                {scores[category]}
                            </span>
                        ))}
                    {variant === 'l3' && (
                        <PerformanceRing score={scores.performance} />
                    )}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <TooltipDetails
                    subject={t('lighthouse.scores')}
                    facts={[
                        ...facts,
                        ...(check?.checked_at
                            ? [format.exact(check.checked_at)]
                            : []),
                    ]}
                    status={{
                        state: scoreState(worst),
                        label: t('Lowest score: {{score}}', { score: worst }),
                    }}
                />
            </TooltipContent>
        </Tooltip>
    );
}

/** The review switcher for the phase 1 variants; `?strip=a&lighthouse=l1&head=h1` (or `?incidents=i1`) selects them too. */
export function SitesVisualMockupSwitcher({
    dimensions,
}: {
    dimensions: MockupDimension[];
}) {
    // MOCKUP ONLY: collapsible so the page footer (pager, page size) stays reviewable.
    const [open, setOpen] = useState(true);
    return (
        <div
            data-mockup-switcher
            className="fixed top-3 right-3 z-50 flex max-w-[min(56rem,calc(100vw-1.5rem))] flex-wrap items-center justify-end gap-x-4 gap-y-2 rounded-[10px] border bg-background/95 px-3 py-2 shadow-lg backdrop-blur"
        >
            {open &&
                dimensions.map((dimension) => (
                    <DimensionControl key={dimension} dimension={dimension} />
                ))}
            <button
                type="button"
                className="text-xs text-muted-foreground underline"
                onClick={() => setOpen((value) => !value)}
            >
                {open ? 'Hide variants' : 'Variants'}
            </button>
        </div>
    );
}
