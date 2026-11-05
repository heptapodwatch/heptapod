import { SectionHeading } from '@/components/section-heading';
import { FormSection } from '@/components/form-section';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageContainer } from '@/components/page-container';
import { useTranslation } from 'react-i18next';
import AppearanceTabs from '@/components/appearance-tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { SectionTabs } from '@/components/section-tabs';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { SegmentedControl } from '@/components/segmented-control';
import {
    StatusDot,
    StatusBadge,
    StateSentence,
    CheckStrip,
    UptimeBar,
    Sparkline,
    MetricTile,
    EventTimeline,
    AttentionRow,
    ClientMark,
    PeekSheet,
    EvidenceBlock,
    DiffBlock,
    ExpiryRow,
    ScoreRing,
    RunBar,
    ScheduleCard,
    RuleRow,
    EmptyState,
    ShownOnceSecret,
    FoundationSkeleton,
    Freshness,
    HealthIndicator,
    type RunState,
} from '@/components/foundation-primitives';
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
import {
    EntityCombobox,
    clientComboboxOptions,
} from '@/components/entity-combobox';
import InputError from '@/components/input-error';
import { MultiStepFormSheet } from '@/components/multi-step-sheet';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';

const runStates: RunState[] = [
    'scheduled',
    'queued',
    'running',
    'done',
    'failed',
    'late',
    'skipped',
];
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
    ['Secondary text', 'foreground-secondary'],
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
                    <AppearanceTabs />
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
            <FormSection
                title={t('Add site')}
                description={t('Preview only. No monitoring data changed.')}
            >
                <MultiStepSheet />
            </FormSection>
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
                    <Button variant="secondary">{t('Secondary')}</Button>
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
                            <ClientMark
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
                {runStates.map((state) => (
                    <RunBar
                        key={state}
                        state={state}
                        description={
                            state === 'running'
                                ? t('Crawl running · page 612 of 1 204')
                                : t('Next run tomorrow 05:00')
                        }
                        progress={state === 'running' ? 51 : undefined}
                        action={
                            state === 'running' ? (
                                <Button variant="ghost" size="sm">
                                    {t('Cancel')}
                                </Button>
                            ) : undefined
                        }
                    />
                ))}
                <ScheduleCard
                    next="09:27:01"
                    last="09:26:01"
                    duration="180–420 ms"
                    state="queued"
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
                    <FoundationSkeleton />
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

function MultiStepSheet({ invalid = false }: { invalid?: boolean }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const form = useForm({ url: '', client: '1' });
    return (
        <MultiStepFormSheet
            open={open}
            onOpenChange={setOpen}
            title="Add site"
            description="Preview only. No monitoring data changed."
            completeLabel="Add site"
            trigger={
                <Button
                    onClick={() => {
                        form.resetAndClearErrors();
                        if (invalid) {
                            form.setData('url', 'http://api.northwind.example');
                            form.setError('url', 'Use an HTTPS URL.');
                        }
                    }}
                >
                    {t(invalid ? 'Show validation errors' : 'Add site')}
                </Button>
            }
            onComplete={() => {
                toast.success(t('Site added. Preview only.'));
                setOpen(false);
            }}
            steps={[
                {
                    title: 'URL',
                    focusId: 'sheet-url',
                    validate: () => {
                        // Fixture-only server response; production callers submit with form.post().
                        if (
                            !URL.canParse(form.data.url) ||
                            new URL(form.data.url).protocol !== 'https:'
                        ) {
                            form.setError('url', 'Use an HTTPS URL.');
                            return false;
                        }
                        form.clearErrors();
                        return true;
                    },
                    content: (
                        <div className="space-y-2">
                            <Label htmlFor="sheet-url">{t('URL')}</Label>
                            <Input
                                id="sheet-url"
                                value={form.data.url}
                                onChange={(event) =>
                                    form.setData('url', event.target.value)
                                }
                                aria-invalid={!!form.errors.url}
                                aria-describedby="sheet-url-help"
                                placeholder="https://shop.acme.example"
                            />
                            {form.errors.url ? (
                                <InputError
                                    id="sheet-url-help"
                                    message={form.errors.url}
                                />
                            ) : (
                                <p
                                    id="sheet-url-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('Use an HTTPS URL.')}
                                </p>
                            )}
                        </div>
                    ),
                },
                {
                    title: 'Client',
                    content: (
                        <div className="space-y-2">
                            <Label htmlFor="sheet-client">{t('Client')}</Label>
                            <EntityCombobox
                                id="sheet-client"
                                options={clientComboboxOptions(clients)}
                                value={form.data.client}
                                onValueChange={(value) =>
                                    form.setData('client', value)
                                }
                            />
                        </div>
                    ),
                },
                {
                    title: 'Review',
                    content: (
                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('URL')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {form.data.url}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Client')}
                                </dt>
                                <dd>
                                    {
                                        clients.find(
                                            (item) =>
                                                String(item.id) ===
                                                form.data.client,
                                        )?.name
                                    }
                                </dd>
                            </div>
                        </dl>
                    ),
                },
            ]}
        />
    );
}
