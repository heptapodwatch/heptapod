import { ChevronRight } from 'lucide-react';
import { useId, useState, type CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';
import { ListFooter } from '@/components/list-footer';
import { TextLink } from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
    listCardPadding,
} from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { cn } from '@/lib/utils';
import { index as sitesIndex } from '@/routes/sites';

type Advisory = Heptapod.Addons.ApplicationHealth.SecurityAdvisoryData;
type Severity = 'critical' | 'high' | 'medium' | 'low';

const severities: Record<
    Severity,
    { rank: number; label: string; state: string; color: string }
> = {
    critical: {
        rank: 4,
        label: 'Critical',
        state: 'down',
        color: 'var(--status-down)',
    },
    high: {
        rank: 3,
        label: 'High',
        state: 'failed',
        color: 'var(--status-down)',
    },
    medium: {
        rank: 2,
        label: 'Medium',
        state: 'warning',
        color: 'var(--status-warn)',
    },
    low: {
        rank: 1,
        label: 'Low',
        state: 'pending',
        color: 'var(--status-error)',
    },
};

/** Severity is website-reported text: only the four known values get a label and tone. */
function knownSeverity(severity: string | null): Severity | null {
    return severity !== null && Object.hasOwn(severities, severity)
        ? (severity as Severity)
        : null;
}

function rank(advisory: Advisory): number {
    const severity = knownSeverity(advisory.severity);
    return severity === null ? 0 : severities[severity].rank;
}

/** Packages by their most severe advisory, then advisory count; advisories by severity, then newest first. */
function packageGroups(advisories: Advisory[]) {
    return [...Map.groupBy(advisories, (advisory) => advisory.package)]
        .map(([name, items]) => {
            const sorted = [...items].sort(
                (a, b) =>
                    rank(b) - rank(a) ||
                    b.reported_at.localeCompare(a.reported_at),
            );
            return { name, items: sorted, top: sorted[0]! };
        })
        .sort(
            (a, b) =>
                rank(b.top) - rank(a.top) ||
                b.items.length - a.items.length ||
                a.name.localeCompare(b.name),
        );
}

export function SeverityBadge({
    severity: reported,
}: {
    severity: string | null;
}) {
    const { t } = useTranslation();
    const severity = knownSeverity(reported);
    if (severity === null) {
        return reported ? <Badge variant="outline">{reported}</Badge> : null;
    }
    return (
        <Badge
            variant="outline"
            className="status-badge"
            data-state={severities[severity].state}
            style={
                {
                    '--status-color': severities[severity].color,
                } as CSSProperties
            }
        >
            {t(severities[severity].label)}
        </Badge>
    );
}

function PackageSummary({
    name,
    items,
    top,
    open,
    controls,
    onToggle,
}: {
    name: string;
    items: Advisory[];
    top: Advisory;
    open: boolean;
    controls: string;
    onToggle: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <Button
                variant="ghost"
                size="sm"
                aria-expanded={open}
                aria-controls={controls}
                onClick={onToggle}
                className="-ml-2 h-auto min-h-8 max-w-full justify-start py-1 font-normal whitespace-normal"
            >
                <ChevronRight
                    aria-hidden
                    className={cn(
                        'size-4 text-muted-foreground transition-transform duration-(--motion-enter) ease-out',
                        open && 'rotate-90',
                    )}
                />
                <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-mono font-medium [overflow-wrap:anywhere]">
                        {name}
                    </span>
                    <SeverityBadge severity={top.severity} />
                    <span className="whitespace-nowrap text-muted-foreground">
                        {t('{{count}} advisories', {
                            count: items.length,
                            formattedCount: format.number(items.length),
                        })}
                    </span>
                </span>
            </Button>
            <TextLink
                variant="secondary"
                href={sitesIndex.url({ query: { 'filter[package]': name } })}
                aria-label={t('Sites affected by {{name}}', { name })}
            >
                {t('Sites affected')}
            </TextLink>
        </div>
    );
}

/**
 * The open advisories of a spatie security advisories result: one summary row per package that expands to its advisories.
 * Desktop is one table, so columns line up across packages; the CVE sits under the title so four columns fit the
 * narrow check-detail card. Below a 40rem container the advisories are stacked cards.
 */
export function SecurityAdvisoryList({
    advisories,
    pagination,
}: {
    advisories: Advisory[];
    /** The advisories are one page of the check's retained advisories. */
    pagination: Heptapod.Contracts.PaginationData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const id = useId();
    const groups = packageGroups(advisories);
    const [open, setOpen] = useState<ReadonlySet<string>>(
        () => new Set(groups.slice(0, 1).map((group) => group.name)),
    );
    const toggle = (name: string) =>
        setOpen((current) => {
            const next = new Set(current);
            if (!next.delete(name)) {
                next.add(name);
            }
            return next;
        });
    const reported = (advisory: Advisory) =>
        format.calendarDate(advisory.reported_at);
    return (
        <div className="@container/advisories space-y-2">
            <div className="hidden overflow-hidden rounded-lg border @[40rem]/advisories:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-28">
                                {t('Severity')}
                            </TableHead>
                            <TableHead>{t('Advisory')}</TableHead>
                            <TableHead>{t('Affected')}</TableHead>
                            <TableHead className="text-right">
                                {t('Reported')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    {groups.map((group, index) => {
                        const rows = `${id}-table-${index}`;
                        const isOpen = open.has(group.name);
                        return [
                            <TableBody
                                key={`${group.name}-summary`}
                                className="border-t"
                            >
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="whitespace-normal"
                                    >
                                        <PackageSummary
                                            {...group}
                                            open={isOpen}
                                            controls={rows}
                                            onToggle={() => toggle(group.name)}
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableBody>,
                            <TableBody
                                key={`${group.name}-rows`}
                                id={rows}
                                hidden={!isOpen}
                                className="border-t"
                            >
                                {group.items.map((advisory) => (
                                    <TableRow key={advisory.advisory_id}>
                                        <TableCell>
                                            <SeverityBadge
                                                severity={advisory.severity}
                                            />
                                        </TableCell>
                                        <TableCell column="primary">
                                            <TextLink
                                                external
                                                href={advisory.link}
                                            >
                                                {advisory.title}
                                            </TextLink>
                                            {advisory.cve && (
                                                <p className="font-mono text-xs text-muted-foreground">
                                                    {advisory.cve}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell column="code" breakAfter="|">
                                            {advisory.affected_versions}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {reported(advisory)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>,
                        ];
                    })}
                </Table>
            </div>
            <ul className="divide-y rounded-lg border @[40rem]/advisories:hidden">
                {groups.map((group, index) => {
                    const cards = `${id}-cards-${index}`;
                    const isOpen = open.has(group.name);
                    return (
                        <li key={group.name}>
                            <div className={listCardPadding}>
                                <PackageSummary
                                    {...group}
                                    open={isOpen}
                                    controls={cards}
                                    onToggle={() => toggle(group.name)}
                                />
                            </div>
                            <ul
                                id={cards}
                                hidden={!isOpen}
                                className="divide-y border-t"
                            >
                                {group.items.map((advisory) => (
                                    <li
                                        key={advisory.advisory_id}
                                        className={cn(
                                            'space-y-1',
                                            listCardPadding,
                                        )}
                                    >
                                        <SeverityBadge
                                            severity={advisory.severity}
                                        />
                                        <div>
                                            <TextLink
                                                external
                                                href={advisory.link}
                                            >
                                                {advisory.title}
                                            </TextLink>
                                        </div>
                                        <p className="font-mono text-xs text-muted-foreground">
                                            {[
                                                advisory.cve,
                                                advisory.affected_versions,
                                                reported(advisory),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </li>
                    );
                })}
            </ul>
            <ListFooter
                count={t('health.advisories_count', {
                    count: pagination.total,
                    formattedCount: format.number(pagination.total),
                })}
                pagination={pagination}
            />
        </div>
    );
}
