// MOCKUP ONLY (branch feat-sites-visual, phase 1, items 5–7). Remove in phase 2.
import {
    ChevronDown,
    Columns3,
    Settings2,
    SlidersHorizontal,
} from 'lucide-react';
import { useSyncExternalStore, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { statusColor } from '@/components/monitoring';
import { SegmentedControl } from '@/components/segmented-control';
import type { Sparkline } from '@/components/sparkline';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useMockupVariant } from '@/lib/sites-visual-variants';
import { checkState } from '@/lib/status';
import type { SiteDto } from '@/types/monitoring';

/* ---------- Item 5: response line colour from the site's own speed ---------- */

/**
 * One rule for the Sites list and the site overview. Today: the site's state. P1: the performance check's state.
 * P2: a neutral line with the slow stretch (above the performance threshold) traced in Warning. P3: the 24 h median
 * against the threshold: under half OK, up to the threshold Warning, above it Failed. Without a performance check: neutral.
 */
export function useResponseLine(
    site: Pick<SiteDto, 'checks' | 'response' | 'response_threshold_ms'>,
): Pick<ComponentProps<typeof Sparkline>, 'color' | 'slowAbove'> {
    const variant = useMockupVariant('spark');
    const neutral = 'var(--muted-foreground)';
    if (variant === 'current') return {};
    const performance = site.checks.find(
        (check) => check.key === 'performance' && check.enabled,
    );
    const threshold = performance ? site.response_threshold_ms : null;
    if (variant === 'p1') {
        const state = performance ? checkState(performance) : 'ok';
        return {
            color:
                state === 'ok' || state === 'pending'
                    ? neutral
                    : statusColor[state],
        };
    }
    if (variant === 'p2') return { color: neutral, slowAbove: threshold };
    const values = site.response
        .flatMap((point) => (point.value === null ? [] : [point.value]))
        .sort((a, b) => a - b);
    const median = values[Math.floor(values.length / 2)];
    if (threshold === null || median === undefined) return { color: neutral };
    return {
        color:
            median > threshold
                ? statusColor.failed
                : median > threshold / 2
                  ? statusColor.warning
                  : statusColor.ok,
    };
}

/* ---------- Item 6: choosable columns ---------- */

/** Status and Site always show; the rest can be hidden. */
export const hideableColumns = [
    ['client_id', 'Client'],
    ['why', 'Why'],
    ['checks', 'Checks'],
    ['lighthouse', 'checks.lighthouse.label'],
    ['response', '24h response'],
    ['uptime', '30-day uptime'],
    ['checked_at', 'Last checked'],
] as const;
export type HideableColumn = (typeof hideableColumns)[number][0];

// A cookie, like the sidebar state: the server could read it for the first render, and it needs no account write.
const columnsCookie = 'sites_hidden_columns';
const columnListeners = new Set<() => void>();

function readHiddenColumns(): string {
    return (
        document.cookie
            .split('; ')
            .find((part) => part.startsWith(`${columnsCookie}=`))
            ?.slice(columnsCookie.length + 1) ?? ''
    );
}

export function useHiddenColumns(): ReadonlySet<HideableColumn> {
    const raw = useSyncExternalStore(
        (listener) => {
            columnListeners.add(listener);
            return () => columnListeners.delete(listener);
        },
        readHiddenColumns,
        () => '',
    );
    return new Set(
        decodeURIComponent(raw)
            .split(',')
            .filter((id): id is HideableColumn =>
                hideableColumns.some(([column]) => column === id),
            ),
    );
}

function setHiddenColumns(hidden: ReadonlySet<HideableColumn>) {
    document.cookie = `${columnsCookie}=${encodeURIComponent([...hidden].join(','))}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    columnListeners.forEach((listener) => listener());
}

const sortOptions = [
    ['status', 'Status'],
    ['name', 'Name A–Z'],
    ['-name', 'Name Z–A'],
] as const;

/** C1: "Columns" in the filter bar; C2: a gear in the table header; C3: a "View" menu with sort and columns. */
export function SitesColumnsMenu({
    trigger,
    sort,
    onSort,
}: {
    trigger: 'c1' | 'c2' | 'c3';
    sort?: string;
    onSort?: (sort: string) => void;
}) {
    const { t } = useTranslation();
    const hidden = useHiddenColumns();
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {trigger === 'c2' ? (
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        aria-label={t('Columns')}
                    >
                        <Settings2 />
                    </Button>
                ) : (
                    <Button variant="outline">
                        {trigger === 'c1' ? (
                            <Columns3 />
                        ) : (
                            <SlidersHorizontal />
                        )}
                        {t(trigger === 'c1' ? 'Columns' : 'View')}
                    </Button>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-52">
                {trigger === 'c3' && onSort && (
                    <>
                        <DropdownMenuLabel>{t('Sort by')}</DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={sort ?? 'status'}
                            onValueChange={onSort}
                        >
                            {sortOptions.map(([value, label]) => (
                                <DropdownMenuRadioItem
                                    key={value}
                                    value={value}
                                >
                                    {t(label)}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                    </>
                )}
                <DropdownMenuLabel>{t('Show columns')}</DropdownMenuLabel>
                {hideableColumns.map(([column, label]) => (
                    <DropdownMenuCheckboxItem
                        key={column}
                        checked={!hidden.has(column)}
                        // The menu stays open, so several columns change in one go.
                        onSelect={(event) => event.preventDefault()}
                        onCheckedChange={(checked) => {
                            const next = new Set(hidden);
                            if (checked) next.delete(column);
                            else next.add(column);
                            setHiddenColumns(next);
                        }}
                    >
                        {t(label)}
                    </DropdownMenuCheckboxItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={hidden.size === 0}
                    onSelect={() => setHiddenColumns(new Set())}
                >
                    {t('Reset columns')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/* ---------- Item 7: page size in the list footer ---------- */

export const pageSizes = [10, 20, 50, 100] as const;

/** S1: a compact "50 per page" menu button; S2: a "Rows" segmented control. Sits beside the pager in ListFooter. */
export function PageSizeControl({
    variant,
    value,
    onChange,
}: {
    variant: 's1' | 's2';
    value: number;
    onChange: (size: number) => void;
}) {
    const { t } = useTranslation();
    if (variant === 's2')
        return (
            <span className="flex items-center gap-2">
                <span>{t('Rows')}</span>
                <SegmentedControl
                    label={t('Rows per page')}
                    value={String(value)}
                    onValueChange={(next) => onChange(Number(next))}
                    options={pageSizes.map((size) => ({
                        value: String(size),
                        label: String(size),
                    }))}
                />
            </span>
        );
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" aria-label={t('Rows per page')}>
                    {t('{{count}} per page', { count: value })}
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={String(value)}
                    onValueChange={(next) => onChange(Number(next))}
                >
                    {pageSizes.map((size) => (
                        <DropdownMenuRadioItem key={size} value={String(size)}>
                            {t('{{count}} per page', { count: size })}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
