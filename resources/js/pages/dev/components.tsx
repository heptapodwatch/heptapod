import { SectionHeading } from '@/components/section-heading';
import { FormSection } from '@/components/form-section';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { ChevronRight } from 'lucide-react';
import { PageContainer } from '@/components/page-container';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { SectionTabs } from '@/components/section-tabs';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { SegmentedControl } from '@/components/segmented-control';
import { EvidenceBlock } from '@/components/evidence-block';
import { ScoreRing } from '@/components/score-ring';
import { ShownOnceSecret } from '@/components/shown-once-secret';
import { rowTargetClass } from '@/components/entity-link';
import {
    StatusDot,
    StatusBadge,
    CheckStrip,
    UptimeBar,
    EventTimeline,
    statusColor,
    ContentState as EmptyState,
} from '@/components/monitoring';
import { TruncatedText } from '@/components/truncated-text';
import { MetricTile, Sparkline } from '@/components/sparkline';
import {
    gallerySites,
    galleryChecks,
    dashboardEvents,
    bucketForState,
    clients,
    states,
    snapshotNow,
    overviewEvents,
} from '@/fixtures/design';
import { setLocale, type Locale } from '@/lib/i18n';
import { statusLabels } from '@/lib/status';
import { cn } from '@/lib/utils';
import { RunLine, RunResultChip } from '@/components/run-family';

const sampleSite: Heptapod.Data.Ui.SiteRowData = gallerySites[0]!;
const sampleChecks: Heptapod.Data.Ui.CheckStripData[] = galleryChecks;
const sampleClient: Heptapod.Data.Ui.ClientData = clients[0]!;
const palette = [
    ['Background', 'background'],
    ['Sidebar', 'sidebar'],
    ['Panel', 'card'],
    ['Muted', 'muted'],
    ['Border', 'border'],
    ['Foreground', 'foreground'],
    ['Primary', 'primary'],
    ['Information', 'status-info'],
    ['Faint text', 'foreground-faint'],
    ['Soft border', 'border-soft'],
    ['Selection tint', 'primary-soft'],
] as const;

export default function Components() {
    const { t, i18n } = useTranslation();
    const [period, setPeriod] = useState('30');
    const { url } = usePage();
    const query = new URL(url, 'http://heptapod-app');
    const section =
        query.searchParams.get('section') === 'uptime' ? 'uptime' : 'overview';
    const sectionHref = (value: string) => {
        const target = new URL(query);
        target.searchParams.set('section', value);
        return target.pathname + target.search;
    };
    return (
        <PageContainer data-foundation-gallery>
            <Head title={t('Components')} />
            <header className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-page-title">{t('Components')}</h1>
                    <p className="text-sm text-muted-foreground">
                        {t('Monitoring primitives. Stock shadcn foundations.')}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl
                        label={t('Language')}
                        value={i18n.language}
                        onValueChange={(value) => setLocale(value as Locale)}
                        options={[
                            { value: 'en', label: 'EN' },
                            { value: 'de', label: 'DE' },
                        ]}
                    />
                </div>
            </header>
            <section className="space-y-4">
                <SectionHeading>
                    {t('Colour · brand and neutrals')}
                </SectionHeading>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    {palette.map(([name, token]) => (
                        <div
                            key={token}
                            className="min-w-0 space-y-1 text-xs text-muted-foreground"
                        >
                            <div
                                className="h-12 rounded-md border"
                                style={{ backgroundColor: `var(--${token})` }}
                            />
                            {t(name)}
                        </div>
                    ))}
                </div>
                <div className="flex flex-wrap gap-3">
                    {[1, 2, 3, 4, 5].map((index) => (
                        <div
                            key={index}
                            className="h-5 w-16 rounded-sm"
                            role="img"
                            aria-label={t('Chart series {{count}}', {
                                count: index,
                            })}
                            style={{ backgroundColor: `var(--chart-${index})` }}
                        />
                    ))}
                </div>
                <div className="space-y-1">
                    <p className="text-page-title">{t('Page title · Geist')}</p>
                    <p className="text-sm">{t('Body · Geist')}</p>
                    <p className="font-mono text-xs tabular-nums">
                        212 ms · 99.98 % · 09:12:04
                    </p>
                </div>
            </section>
            <section className="space-y-4">
                <SectionHeading>{t('Colour · status')}</SectionHeading>
                <div className="flex flex-wrap gap-3">
                    {states.map((state) => (
                        <div
                            key={state}
                            className="flex flex-col items-start gap-2 rounded-md border p-3"
                        >
                            <StatusDot state={state} />
                            <StatusBadge state={state} />
                        </div>
                    ))}
                </div>
            </section>
            <section className="space-y-4">
                <SectionHeading>{t('Buttons and choice')}</SectionHeading>
                <div className="flex flex-wrap items-center gap-2">
                    <Button>{t('Open incident')}</Button>
                    <Button variant="outline">{t('Run check')}</Button>
                    <Button variant="ghost">{t('Ghost')}</Button>
                    <Button variant="destructive">{t('Delete site')}</Button>
                    <Button disabled>{t('Disabled')}</Button>
                    <Input className="w-48" placeholder={t('Filter sites…')} />
                    <Switch aria-label={t('Enabled')} />
                    <Checkbox aria-label={t('Enabled')} defaultChecked />
                    <SegmentedControl
                        label={t('Time range')}
                        value={period}
                        onValueChange={setPeriod}
                        options={[
                            { value: '24', label: '24 h' },
                            { value: '7', label: '7 d' },
                            { value: '30', label: '30 d' },
                        ]}
                    />
                    <Badge>{t('Later')}</Badge>
                </div>
            </section>
            <section className="space-y-4">
                <SectionHeading>{t('Monitoring primitives')}</SectionHeading>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('States')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {states.map((state) => (
                                <StateSentence
                                    key={state}
                                    state={state}
                                    sentence={t('State: {{state}}', {
                                        state: t(statusLabels[state]),
                                    })}
                                    detail={t('On 4 of 64 sites')}
                                />
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('CheckStrip')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <CheckStrip checks={sampleChecks} />
                            <CheckStrip
                                checks={sampleChecks}
                                runningCheckIds={[sampleChecks[0]!.id]}
                            />
                            <GalleryClientMark
                                name={sampleClient.name}
                                initials="AC"
                                color="#7c5e10"
                            />
                            <Freshness state="live" />
                            <Freshness
                                state="reconnecting"
                                lastUpdated="09:26"
                            />
                            <Freshness state="offline" />
                            <HealthIndicator
                                state="ok"
                                label={t('Monitoring healthy')}
                                updatedAt="4 s"
                                updatedAtDateTime={new Date(
                                    snapshotNow - 4000,
                                ).toISOString()}
                            />
                            <HealthIndicator
                                state="warning"
                                label={t('Monitoring degraded')}
                                updatedAt="4 h"
                                updatedAtDateTime={new Date(
                                    snapshotNow - 4 * 3600000,
                                ).toISOString()}
                            />
                            <HealthIndicator
                                state="check-error"
                                label={t('Monitoring stopped')}
                                updatedAt="8 h"
                                updatedAtDateTime={new Date(
                                    snapshotNow - 8 * 3600000,
                                ).toISOString()}
                            />
                        </CardContent>
                    </Card>
                </div>
                <UptimeBar
                    buckets={Array.from({ length: 90 }, (_, day) =>
                        bucketForState(
                            day === 40 ? 'down' : day === 65 ? 'warning' : 'ok',
                            new Date(Date.UTC(2026, 9, 1 + day)).toISOString(),
                            100,
                        ),
                    )}
                />
                <div className="grid gap-4 md:grid-cols-3">
                    <MetricTile
                        label={t('Uptime, 30 days')}
                        value="99.98 %"
                        delta={t('3 incidents · 41 min')}
                        data={sampleSite.response}
                    />
                    <MetricTile
                        label={t('Expiring in 30 days')}
                        value="7"
                        data={[]}
                        state="warning"
                    />
                    <MetricTile
                        label={t('Scheduled tasks')}
                        value="38"
                        data={[]}
                        state="failed"
                    />
                </div>
                <Sparkline data={sampleSite.response} state="down" />
                <div className="flex gap-4">
                    <ScoreRing score={92} label={t('Performance')} />
                    <ScoreRing score={61} label={t('Accessibility')} />
                    <ScoreRing score={38} label={t('SEO')} />
                </div>
                <ExpiryRow
                    label="shop.acme.example"
                    daysLeft={6}
                    windowDays={90}
                    state="warning"
                />
            </section>
            <section className="space-y-4">
                <SectionHeading>{t('Check runs')}</SectionHeading>
                <RunLine phase="queued" label={t('Queued')} detail="#2" />
                <RunLine
                    phase="running"
                    label={t('Running')}
                    detail="612 / 1,204"
                    progress={{ done: 612, total: 1204 }}
                    bar
                    mine
                />
                <RunLine phase="running" label={t('Running')} />
                {(['ok', 'warning', 'failed', 'check-error'] as const).map(
                    (outcome) => (
                        <RunResultChip key={outcome} outcome={outcome} />
                    ),
                )}
                <ScheduleCard
                    next="09:27:01"
                    last="09:26:01"
                    duration="180–420 ms"
                />
            </section>
            <section className="grid gap-5 md:grid-cols-2">
                <div className="space-y-3">
                    <SectionHeading>{t('Evidence and rows')}</SectionHeading>
                    <EvidenceBlock
                        title={t('Latest result')}
                        rows={[
                            { label: t('Status'), value: 'HTTP 503' },
                            { label: t('Time'), value: '09:13' },
                        ]}
                    />
                    <DiffBlock before="192.0.2.1" after="192.0.2.2" />
                    <AttentionRow
                        site={sampleSite}
                        summary="HTTP 503"
                        state="down"
                        href="/sites"
                    />
                    <RuleRow
                        name={t('Email alert')}
                        detail={t('Immediately')}
                        enabled
                    />
                    <RuleRow
                        name={t('Email alert')}
                        detail={t('Paused')}
                        enabled={false}
                    />
                    <FormSection
                        title={t('Notifications')}
                        description={t('Email alerts')}
                    >
                        <Input aria-label={t('Email alerts')} />
                    </FormSection>
                    <ShownOnceSecret
                        label={t('API token')}
                        value="demo-token-not-a-secret"
                    />
                    <PeekSheet
                        title={sampleSite.name}
                        description={t('Site details')}
                        trigger={
                            <Button variant="outline">{t('Open peek')}</Button>
                        }
                    >
                        <StatusBadge state="down" />
                    </PeekSheet>
                </div>
                <div className="space-y-3">
                    <SectionHeading>
                        {t('Timeline · empty · skeleton')}
                    </SectionHeading>
                    <EventTimeline
                        events={[
                            ...dashboardEvents.slice(0, 2),
                            ...overviewEvents(sampleSite),
                        ]}
                    />
                    <EmptyState
                        state="empty"
                        title={t('No sites yet')}
                        description={t('Paste one address or a list.')}
                    />
                    <EmptyState state="error" />
                    <GallerySkeleton />
                    <Skeleton className="h-10 w-full" />
                    <SectionTabs
                        label={t('Checks')}
                        value={section}
                        items={[
                            {
                                value: 'overview',
                                label: t('Overview'),
                                href: sectionHref('overview'),
                            },
                            {
                                value: 'uptime',
                                label: t('Uptime'),
                                href: sectionHref('uptime'),
                            },
                        ]}
                    />
                    <div>
                        {t(section === 'overview' ? 'Overview' : 'Uptime')}
                    </div>
                </div>
            </section>
        </PageContainer>
    );
}

function StateSentence({
    state,
    sentence,
    detail,
    action,
    suffix,
}: {
    state: Heptapod.Enums.SiteStatus;
    sentence: ReactNode;
    detail?: ReactNode;
    action?: ReactNode;
    suffix?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
                <h2 className="flex flex-wrap items-center gap-3 text-headline">
                    <StatusBadge state={state} />
                    {sentence}
                    {suffix}
                </h2>
                {detail && (
                    <p className="text-sm text-muted-foreground">{detail}</p>
                )}
            </div>
            {action}
        </div>
    );
}

function GalleryClientMark({
    name,
    initials,
    color,
    size = 'md',
}: {
    name: string;
    initials: string;
    color: string;
    size?: 'sm' | 'md';
}) {
    return (
        <span
            role="img"
            aria-label={name}
            className={`inline-flex shrink-0 items-center justify-center rounded-md font-mono font-semibold text-white ${size === 'sm' ? 'size-4 text-mark' : 'size-7 text-xs'}`}
            style={{ backgroundColor: color }}
        >
            {initials}
        </span>
    );
}

function HealthIndicator({
    state,
    label,
    updatedAt,
    updatedAtDateTime,
}: {
    state: 'ok' | 'warning' | 'check-error';
    label: string;
    updatedAt: string;
    updatedAtDateTime: string;
}) {
    return (
        <div className="flex min-h-8 items-center gap-2 rounded-md border border-border-soft px-2 text-xs text-muted-foreground">
            <StatusDot state={state} label={label} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <time
                dateTime={updatedAtDateTime}
                className="font-mono tabular-nums"
            >
                {updatedAt}
            </time>
        </div>
    );
}

function Freshness({
    state,
    lastUpdated,
}: {
    state: 'live' | 'reconnecting' | 'offline';
    lastUpdated?: string;
}) {
    const { t } = useTranslation();
    return (
        <StatusBadge
            state={state === 'live' ? 'ok' : 'warning'}
            label={
                state === 'live'
                    ? t('Live')
                    : state === 'reconnecting'
                      ? t('Reconnecting · data from {{time}}', {
                            time: lastUpdated ?? '—',
                        })
                      : t('Offline')
            }
        />
    );
}

function PeekSheet({
    title,
    description,
    trigger,
    children,
    open,
    onOpenChange,
}: {
    title: string;
    description: string;
    trigger?: ReactNode;
    children: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent className="w-full overflow-y-auto sm:max-w-[520px]">
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>
                <div className="p-4">{children}</div>
            </SheetContent>
        </Sheet>
    );
}

function DiffBlock({ before, after }: { before: string; after: string }) {
    const { t } = useTranslation();
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <EvidenceBlock
                title={t('Before')}
                rows={[{ label: t('Value'), value: before }]}
            />
            <EvidenceBlock
                title={t('After')}
                rows={[{ label: t('Value'), value: after }]}
            />
        </div>
    );
}

function ExpiryRow({
    label,
    daysLeft,
    windowDays,
    state,
}: {
    label: string;
    daysLeft: number;
    windowDays: number;
    state: Heptapod.Enums.SiteStatus;
}) {
    const { t } = useTranslation();
    return (
        <div className="flex items-center gap-3 text-sm">
            <StatusDot state={state} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <div className="h-1 w-28 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full"
                    style={{
                        backgroundColor: statusColor[state],
                        width: `${Math.min(100, Math.max(0, (daysLeft / windowDays) * 100))}%`,
                    }}
                />
            </div>
            <span className="font-mono text-xs tabular-nums">
                {t('{{count}} d', { count: daysLeft })}
            </span>
        </div>
    );
}

function ScheduleCard({
    next,
    last,
    duration,
    interval,
    showRunsLink = true,
}: {
    next: ReactNode;
    last: ReactNode;
    duration: string;
    interval?: string;
    showRunsLink?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Schedule')}</CardTitle>
                {showRunsLink && (
                    <CardAction>
                        <Button variant="ghost" disabled>
                            {t('Runs')} <ChevronRight className="size-4" />
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                <dl className="grid grid-cols-[6rem_1fr] gap-y-2 text-xs">
                    {interval && (
                        <>
                            <dt className="text-muted-foreground">
                                {t('Every')}
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {interval}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">{t('Next run')}</dt>
                    <dd className="font-mono tabular-nums">{next}</dd>
                    <dt className="text-muted-foreground">{t('Last run')}</dt>
                    <dd className="font-mono tabular-nums">{last}</dd>
                    <dt className="text-muted-foreground">
                        {t('Usually takes')}
                    </dt>
                    <dd className="font-mono tabular-nums">{duration}</dd>
                </dl>
                {showRunsLink && (
                    <p className="text-xs text-foreground-faint">
                        {t('Every run is listed in Runs')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function AttentionRow({
    site,
    summary,
    state,
    href,
}: {
    site: Heptapod.Data.Ui.SiteRowData;
    summary: string;
    state: Heptapod.Enums.SiteStatus;
    href: string;
}) {
    return (
        <Link
            href={href}
            prefetch
            className={cn(
                'flex min-h-10 items-center gap-3 border-b px-3 text-sm',
                rowTargetClass,
            )}
        >
            <StatusDot state={state} />
            <TruncatedText content={site.name}>
                <span className="min-w-0 flex-1 truncate">{site.name}</span>
            </TruncatedText>
            <TruncatedText content={summary}>
                <span className="hidden min-w-0 flex-1 truncate text-muted-foreground sm:block">
                    {summary}
                </span>
            </TruncatedText>
            <StatusBadge state={state} />
        </Link>
    );
}

function RuleRow({
    name,
    detail,
    enabled,
}: {
    name: string;
    detail: string;
    enabled: boolean;
}) {
    return (
        <div className="flex min-h-10 items-center gap-3 border-b px-3 text-sm">
            <TruncatedText content={name}>
                <span className="min-w-0 flex-1 truncate">{name}</span>
            </TruncatedText>
            <span className="text-xs text-muted-foreground">{detail}</span>
            <StatusBadge
                state={enabled ? 'ok' : 'disabled'}
                label={enabled ? 'Enabled' : 'Disabled'}
            />
        </div>
    );
}

function GallerySkeleton() {
    return (
        <div className="space-y-3" aria-busy="true">
            <Skeleton className="h-6 w-1/2" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}
