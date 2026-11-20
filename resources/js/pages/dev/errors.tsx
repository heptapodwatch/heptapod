import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Bell,
    Clock,
    Expand,
    Info,
    Maximize2,
    Minimize2,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import AppearanceTabs from '@/components/appearance-tabs';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { SectionHeading } from '@/components/section-heading';
import { SegmentedControl } from '@/components/segmented-control';
import { StatusDot } from '@/components/monitoring';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { setLocale, type Locale } from '@/lib/i18n';
import {
    ERROR_FAMILIES,
    INVENTORY_TOTALS,
    type ErrorApproach,
    type ErrorFamilyId,
    type ErrorFamilySpec,
    type LocalizedText,
    type MockLocale,
} from './error-families';

type ViewMode = 'compare' | ErrorApproach;

const APPROACH_LABELS: Record<ErrorApproach, LocalizedText> = {
    a: { en: 'A · In-shell page', de: 'A · App-Seite' },
    b: { en: 'B · Standalone', de: 'B · Eigenständig' },
    c: { en: 'C · In-place', de: 'C · Vor Ort' },
};

function pick(text: LocalizedText, lang: MockLocale): string {
    return text[lang];
}

function BrandLockup() {
    return (
        <span
            className="hp-brand [--brand-mark-h:28px]"
            role="img"
            aria-label="Heptapod"
        >
            <svg
                className="hp-mark"
                data-state="still"
                viewBox="33.5 19.5 53 81"
                aria-hidden="true"
                focusable="false"
            >
                <rect
                    className="hp-seg hp-a"
                    rx="6.5"
                    x="49"
                    y="19.5"
                    width="22"
                    height="13"
                />
                <rect
                    className="hp-seg hp-b"
                    data-lit=""
                    rx="6.5"
                    x="73.5"
                    y="28.5"
                    width="13"
                    height="29"
                />
                <rect
                    className="hp-seg hp-c"
                    data-lit=""
                    rx="6.5"
                    x="73.5"
                    y="62.5"
                    width="13"
                    height="29"
                />
                <rect
                    className="hp-seg hp-d"
                    rx="6.5"
                    x="49"
                    y="87.5"
                    width="22"
                    height="13"
                />
                <rect
                    className="hp-seg hp-e"
                    data-lit=""
                    rx="6.5"
                    x="33.5"
                    y="62.5"
                    width="13"
                    height="29"
                />
                <rect
                    className="hp-seg hp-f"
                    data-lit=""
                    rx="6.5"
                    x="33.5"
                    y="28.5"
                    width="13"
                    height="29"
                />
                <rect
                    className="hp-seg hp-g"
                    data-lit=""
                    rx="6.5"
                    x="49"
                    y="53.5"
                    width="22"
                    height="13"
                />
            </svg>
            <span className="hp-word" aria-hidden="true" />
        </span>
    );
}

/** Approach A: Inertia error page rendered inside AppLayout (keeps sidebar & search). */
function InShellErrorPreview({
    family,
    lang,
    fullscreen = false,
}: {
    family: ErrorFamilySpec;
    lang: MockLocale;
    fullscreen?: boolean;
}) {
    const recoveryText = pick(family.page.recoveryLabel, lang);
    const secondaryText = family.page.secondaryLabel
        ? pick(family.page.secondaryLabel, lang)
        : null;

    return (
        <div
            className={
                fullscreen
                    ? 'flex min-h-[68vh] flex-col items-center justify-center py-12'
                    : 'flex min-h-72 flex-col items-center justify-center rounded-lg border bg-background px-6 py-10'
            }
        >
            <div className="flex w-full max-w-md flex-col items-center gap-5 text-center">
                {family.unsafeInShell && (
                    <Alert tone="danger">
                        <AlertTitle>
                            {lang === 'de'
                                ? 'Im App-Rahmen nicht sicher'
                                : 'Unsafe inside the app shell'}
                        </AlertTitle>
                        <AlertDescription>
                            {lang === 'de'
                                ? 'Geteilte AppLayout-Daten fragen die Datenbank ab und scheitern bei 500/503 erneut. Hier muss Ansatz B (Blade) greifen.'
                                : 'AppLayout shared props query the database and fail again during 500/503 outages. Approach B (zero-DB Blade) is required.'}
                        </AlertDescription>
                    </Alert>
                )}
                <div className="space-y-2">
                    <p className="font-mono text-sm text-muted-foreground">
                        HTTP {family.statusSample}
                    </p>
                    <p className="text-page-title text-foreground">
                        {pick(family.page.title, lang)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {pick(family.page.description, lang)}
                    </p>
                </div>
                <div className="flex flex-wrap items-center justify-center gap-2">
                    <Button type="button">{recoveryText}</Button>
                    {secondaryText && (
                        <Button type="button" variant="outline">
                            {secondaryText}
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    {lang === 'de'
                        ? 'Seitenleiste, Befehlspalette (⌘K) und Konto-Menü bleiben erreichbar.'
                        : 'Sidebar, command palette (⌘K), and account menu stay reachable.'}
                </p>
            </div>
        </div>
    );
}

/** Approach B: Standalone branded Blade page (exact visual match of resources/views/errors/error.blade.php). */
function StandaloneBladePreview({
    family,
    lang,
    fullscreen = false,
}: {
    family: ErrorFamilySpec;
    lang: MockLocale;
    fullscreen?: boolean;
}) {
    const recoveryText = pick(family.page.recoveryLabel, lang);

    return (
        <div
            className={
                fullscreen
                    ? 'flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10'
                    : 'flex min-h-72 flex-col items-center justify-center rounded-lg border bg-background p-6 md:p-8'
            }
        >
            <div className="flex w-full max-w-sm flex-col items-center gap-6 text-center">
                <BrandLockup />
                <div className="space-y-2">
                    <p className="font-mono text-sm text-muted-foreground">
                        {family.statusSample}
                    </p>
                    <p className="text-page-title text-foreground">
                        {pick(family.page.title, lang)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {pick(family.page.description, lang)}
                    </p>
                </div>
                <Button type="button">{recoveryText}</Button>
            </div>
        </div>
    );
}

/** Static preview of a Sonner flash toast plus live trigger button. */
function ToastPreviewCard({
    tone,
    message,
    actionLabel,
    lang,
}: {
    tone: 'warning' | 'error' | 'info';
    message: string;
    actionLabel?: string;
    lang: MockLocale;
}) {
    const Icon =
        tone === 'error'
            ? AlertCircle
            : tone === 'warning'
              ? TriangleAlert
              : Info;

    const triggerLiveToast = () => {
        const opts = actionLabel
            ? {
                  action: {
                      label: actionLabel,
                      onClick: () => {},
                  },
              }
            : undefined;
        toast[tone](message, opts);
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-label font-medium text-muted-foreground">
                    {lang === 'de'
                        ? 'Flash-Toast (bei Überschneidung / Aktion)'
                        : 'Flash toast (on race / refused action)'}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    onClick={triggerLiveToast}
                >
                    <Bell className="size-3.5" aria-hidden="true" />
                    {lang === 'de' ? 'Live-Toast testen' : 'Trigger live toast'}
                </Button>
            </div>
            <div className="flex items-start gap-2.5 rounded-lg border bg-popover px-3.5 py-3 text-sm text-popover-foreground shadow-overlay">
                <Icon
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div className="min-w-0 flex-1 space-y-1">
                    <p className="text-sm leading-snug">{message}</p>
                </div>
                {actionLabel && (
                    <Button type="button" variant="outline" size="xs">
                        {actionLabel}
                    </Button>
                )}
            </div>
        </div>
    );
}

/** Approach C: In-place prevention (disabled control + reason) + toast notice + inline banner. */
function InPlacePreview({
    family,
    lang,
}: {
    family: ErrorFamilySpec;
    lang: MockLocale;
}) {
    const scene = family.inPlace;

    return (
        <div className="space-y-4 rounded-lg border bg-background p-4 md:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b pb-3">
                <div className="min-w-0 space-y-0.5">
                    <div className="flex items-center gap-2">
                        <StatusDot
                            state={
                                family.id === 'f12'
                                    ? 'paused'
                                    : family.id === 'f9' || family.id === 'f6'
                                      ? 'failed'
                                      : 'warning'
                            }
                            label={pick(family.name, lang)}
                        />
                        <p className="truncate text-section text-foreground">
                            {pick(scene.sceneTitle, lang)}
                        </p>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {pick(scene.sceneSubtitle, lang)}
                    </p>
                </div>

                {scene.controlLabel && (
                    <div className="flex flex-col items-start gap-1 sm:items-end">
                        <Button type="button" disabled>
                            {pick(scene.controlLabel, lang)}
                        </Button>
                        {scene.preventReason && (
                            <p
                                className="flex items-center gap-1.5 text-xs text-muted-foreground"
                                role="status"
                            >
                                <Clock
                                    className="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{pick(scene.preventReason, lang)}</span>
                            </p>
                        )}
                    </div>
                )}
            </div>

            <ToastPreviewCard
                tone={scene.toastTone}
                message={pick(scene.toastMessage, lang)}
                actionLabel={
                    scene.toastActionLabel
                        ? pick(scene.toastActionLabel, lang)
                        : undefined
                }
                lang={lang}
            />

            <div className="space-y-2">
                <p className="text-label font-medium text-muted-foreground">
                    {lang === 'de'
                        ? 'Inline-Hinweis (in Karte, Sheet oder Seitenkopf)'
                        : 'Inline banner (inside card, sheet, or header notices)'}
                </p>
                <Alert
                    tone={scene.bannerTone}
                    action={
                        scene.bannerActionLabel ? (
                            <Button type="button" variant="outline">
                                {pick(scene.bannerActionLabel, lang)}
                            </Button>
                        ) : undefined
                    }
                >
                    <AlertTitle>{pick(scene.bannerTitle, lang)}</AlertTitle>
                    <AlertDescription>
                        {pick(scene.bannerDescription, lang)}
                    </AlertDescription>
                </Alert>
            </div>

            {scene.fieldError && (
                <div className="space-y-1">
                    <p className="text-label font-medium text-muted-foreground">
                        {lang === 'de'
                            ? 'Feld-Hinweis bei Upload'
                            : 'Inline field error on upload'}
                    </p>
                    <InputError message={pick(scene.fieldError, lang)} />
                </div>
            )}

            {scene.extraDetail && (
                <p className="text-xs text-muted-foreground">
                    {pick(scene.extraDetail, lang)}
                </p>
            )}
        </div>
    );
}

function ApproachCard({
    approach,
    family,
    lang,
    onFullscreen,
}: {
    approach: ErrorApproach;
    family: ErrorFamilySpec;
    lang: MockLocale;
    onFullscreen?: () => void;
}) {
    const isRecommended = family.recommended === approach;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle>
                        {pick(APPROACH_LABELS[approach], lang)}
                    </CardTitle>
                    {isRecommended && (
                        <Badge variant="secondary">
                            {lang === 'de' ? 'Empfohlen' : 'Recommended'}
                        </Badge>
                    )}
                    {approach === 'a' && family.unsafeInShell && (
                        <Badge variant="outline">
                            {lang === 'de' ? 'Nicht sicher' : 'Unsafe'}
                        </Badge>
                    )}
                </div>
                <CardDescription>
                    {pick(family.verdicts[approach], lang)}
                </CardDescription>
                {onFullscreen && (
                    <CardAction>
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            onClick={onFullscreen}
                        >
                            <Expand className="size-3.5" aria-hidden="true" />
                            {lang === 'de' ? 'Vollbild' : 'Fullscreen'}
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="space-y-3">
                {approach === 'a' && (
                    <InShellErrorPreview family={family} lang={lang} />
                )}
                {approach === 'b' && (
                    <StandaloneBladePreview family={family} lang={lang} />
                )}
                {approach === 'c' && (
                    <InPlacePreview family={family} lang={lang} />
                )}
            </CardContent>
        </Card>
    );
}

export default function ErrorMockups() {
    const { i18n } = useTranslation();
    const { url } = usePage();
    const initialQuery = new URL(url, 'http://heptapod-app').searchParams;

    const initialFamily =
        ERROR_FAMILIES.find((item) => item.id === initialQuery.get('family'))
            ?.id ?? 'f1';
    const rawApproach = initialQuery.get('approach');
    const initialMode: ViewMode =
        rawApproach === 'a' ||
        rawApproach === 'b' ||
        rawApproach === 'c' ||
        rawApproach === 'compare'
            ? rawApproach
            : 'compare';
    const initialFullscreen =
        initialQuery.get('fullscreen') === '1' &&
        (initialMode === 'a' || initialMode === 'b');

    const [familyId, setFamilyId] = useState<ErrorFamilyId>(initialFamily);
    const [mode, setMode] = useState<ViewMode>(initialMode);
    const [fullscreen, setFullscreen] = useState<boolean>(initialFullscreen);

    const lang: MockLocale = i18n.language === 'de' ? 'de' : 'en';
    const family =
        ERROR_FAMILIES.find((item) => item.id === familyId) ??
        ERROR_FAMILIES[0]!;

    const syncUrl = (
        nextFamily: ErrorFamilyId,
        nextMode: ViewMode,
        nextFullscreen: boolean,
    ) => {
        if (typeof window === 'undefined') return;
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('family', nextFamily);
        nextUrl.searchParams.set('approach', nextMode);
        if (nextFullscreen) nextUrl.searchParams.set('fullscreen', '1');
        else nextUrl.searchParams.delete('fullscreen');
        window.history.replaceState(window.history.state, '', nextUrl);
    };

    const selectFamily = (next: ErrorFamilyId) => {
        setFamilyId(next);
        syncUrl(next, mode, fullscreen);
    };

    const selectMode = (next: ViewMode) => {
        const keepFullscreen = fullscreen && (next === 'a' || next === 'b');
        setMode(next);
        setFullscreen(keepFullscreen);
        syncUrl(familyId, next, keepFullscreen);
    };

    const enterFullscreen = (approach: 'a' | 'b') => {
        setMode(approach);
        setFullscreen(true);
        syncUrl(familyId, approach, true);
    };

    const exitFullscreen = () => {
        setFullscreen(false);
        syncUrl(familyId, mode, false);
    };

    // Fullscreen Approach B: true viewport standalone Blade simulation.
    if (fullscreen && mode === 'b') {
        return (
            <div className="fixed inset-0 z-50 overflow-y-auto bg-background">
                <Head
                    title={`${pick(family.page.title, lang)} · ${family.code}`}
                />
                <div className="fixed top-4 right-4 z-10 flex items-center gap-2">
                    <SegmentedControl
                        label="Language"
                        value={lang}
                        onValueChange={(value) => setLocale(value as Locale)}
                        options={[
                            { value: 'en', label: 'EN' },
                            { value: 'de', label: 'DE' },
                        ]}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={exitFullscreen}
                    >
                        <Minimize2 className="size-3.5" aria-hidden="true" />
                        {lang === 'de' ? 'Vorschau schließen' : 'Exit preview'}
                    </Button>
                </div>
                <StandaloneBladePreview
                    family={family}
                    lang={lang}
                    fullscreen
                />
            </div>
        );
    }

    // Fullscreen Approach A: true AppLayout shell view with gallery chrome collapsed.
    if (fullscreen && mode === 'a') {
        return (
            <PageContainer>
                <Head
                    title={`${pick(family.page.title, lang)} · ${family.code}`}
                />
                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Badge variant="outline">{family.code}</Badge>
                        <span>
                            {lang === 'de'
                                ? 'Vollbild-Vorschau · Ansatz A (Inertia-Seite im App-Rahmen)'
                                : 'Fullscreen preview · Approach A (Inertia page in app shell)'}
                        </span>
                    </div>
                    <div className="flex items-center gap-2">
                        <AppearanceTabs />
                        <SegmentedControl
                            label="Language"
                            value={lang}
                            onValueChange={(value) =>
                                setLocale(value as Locale)
                            }
                            options={[
                                { value: 'en', label: 'EN' },
                                { value: 'de', label: 'DE' },
                            ]}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={exitFullscreen}
                        >
                            <Minimize2
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            {lang === 'de'
                                ? 'Zurück zur Übersicht'
                                : 'Back to gallery'}
                        </Button>
                    </div>
                </div>
                <InShellErrorPreview family={family} lang={lang} fullscreen />
            </PageContainer>
        );
    }

    return (
        <PageContainer className="space-y-6">
            <Head
                title={
                    lang === 'de'
                        ? 'Fehlerbehandlung · Entwürfe'
                        : 'Graceful errors · Mockups'
                }
            />

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <Button asChild variant="ghost" size="xs">
                            <Link href="/dev/components">
                                <ArrowLeft
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {lang === 'de' ? 'Komponenten' : 'Components'}
                            </Link>
                        </Button>
                        <Badge variant="outline">Phase A · Mockups</Badge>
                    </div>
                    <h1 className="text-page-title">
                        {lang === 'de'
                            ? 'Fehler & Ablehnungen — Entwürfe'
                            : 'Graceful errors — Decision mockups'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {lang === 'de'
                            ? `${INVENTORY_TOTALS.total} Stellen im Bestand (${INVENTORY_TOTALS.expected} erwartet · ${INVENTORY_TOTALS.unexpected} unerwartet · ${INVENTORY_TOTALS.apiOnly} nur API) in 12 Fallgruppen.`
                            : `${INVENTORY_TOTALS.total} inventoried paths (${INVENTORY_TOTALS.expected} expected · ${INVENTORY_TOTALS.unexpected} unexpected · ${INVENTORY_TOTALS.apiOnly} API only) grouped into 12 case families.`}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <AppearanceTabs />
                    <SegmentedControl
                        label={lang === 'de' ? 'Sprache' : 'Language'}
                        value={lang}
                        onValueChange={(value) => setLocale(value as Locale)}
                        options={[
                            { value: 'en', label: 'EN' },
                            { value: 'de', label: 'DE' },
                        ]}
                    />
                </div>
            </header>

            {/* Family selector */}
            <section className="space-y-3">
                <SectionHeading>
                    {lang === 'de'
                        ? '1. Fallgruppe wählen (F1–F12)'
                        : '1. Pick a case family (F1–F12)'}
                </SectionHeading>
                <div className="flex flex-wrap gap-1.5">
                    {ERROR_FAMILIES.map((item) => {
                        const selected = item.id === family.id;
                        return (
                            <Button
                                key={item.id}
                                type="button"
                                variant={selected ? 'default' : 'outline'}
                                className="max-w-full"
                                onClick={() => selectFamily(item.id)}
                            >
                                <span className="shrink-0 font-mono text-xs">
                                    {item.code}
                                </span>
                                <span className="truncate">
                                    {pick(item.name, lang)}
                                </span>
                                <span className="shrink-0 font-mono text-xs opacity-70">
                                    {item.statusSample}
                                </span>
                            </Button>
                        );
                    })}
                </div>
            </section>

            {/* Active family overview + approach switcher */}
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="font-mono">
                            {family.code} · {family.statuses}
                        </Badge>
                        <CardTitle>{pick(family.name, lang)}</CardTitle>
                        <Badge variant="secondary">
                            {lang === 'de' ? 'Empfehlung: ' : 'Recommended: '}
                            {pick(APPROACH_LABELS[family.recommended], lang)}
                        </Badge>
                    </div>
                    <CardDescription>
                        {pick(family.recommendedSplit, lang)}
                    </CardDescription>
                    <CardAction>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="xs"
                                onClick={() => enterFullscreen('a')}
                            >
                                <Maximize2
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {lang === 'de' ? 'Vollbild A' : 'Fullscreen A'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="xs"
                                onClick={() => enterFullscreen('b')}
                            >
                                <Maximize2
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {lang === 'de' ? 'Vollbild B' : 'Fullscreen B'}
                            </Button>
                        </div>
                    </CardAction>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="max-w-full overflow-x-auto">
                            <SegmentedControl
                                label={lang === 'de' ? 'Ansatz' : 'Approach'}
                                value={mode}
                                onValueChange={(value) =>
                                    selectMode(value as ViewMode)
                                }
                                options={[
                                    {
                                        value: 'compare',
                                        label:
                                            lang === 'de' ? 'Alle 3' : 'All 3',
                                    },
                                    {
                                        value: 'a',
                                        label:
                                            lang === 'de'
                                                ? 'A · App'
                                                : 'A · Shell',
                                    },
                                    {
                                        value: 'b',
                                        label:
                                            lang === 'de'
                                                ? 'B · Blade'
                                                : 'B · Blade',
                                    },
                                    {
                                        value: 'c',
                                        label:
                                            lang === 'de'
                                                ? 'C · Vor Ort'
                                                : 'C · In-place',
                                    },
                                ]}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2 font-mono text-xs text-muted-foreground">
                            <span>
                                {lang === 'de' ? 'Erwartet' : 'Expected'}:{' '}
                                {family.counts.expected}
                            </span>
                            <span>·</span>
                            <span>
                                {lang === 'de' ? 'Unerwartet' : 'Unexpected'}:{' '}
                                {family.counts.unexpected}
                            </span>
                            <span>·</span>
                            <span>
                                {lang === 'de' ? 'Nur API' : 'API only'}:{' '}
                                {family.counts.apiOnly}
                            </span>
                        </div>
                    </div>

                    <div className="space-y-1.5 text-xs text-muted-foreground">
                        <div className="flex items-center gap-1.5">
                            <ShieldAlert
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="font-medium text-foreground">
                                {lang === 'de'
                                    ? 'Beispiele aus dem Code:'
                                    : 'Inventory examples:'}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {family.examples.map((example) => (
                                <span
                                    key={example}
                                    className="max-w-full rounded-md border bg-muted/40 px-2 py-0.5 font-mono text-xs break-words text-foreground"
                                >
                                    {example}
                                </span>
                            ))}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Approach previews */}
            <section className="space-y-4">
                <SectionHeading>
                    {lang === 'de'
                        ? '2. Nutzeransicht vergleichen'
                        : '2. Compare user-facing outcomes'}
                </SectionHeading>

                {mode === 'compare' ? (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <ApproachCard
                            approach="a"
                            family={family}
                            lang={lang}
                            onFullscreen={() => enterFullscreen('a')}
                        />
                        <ApproachCard
                            approach="b"
                            family={family}
                            lang={lang}
                            onFullscreen={() => enterFullscreen('b')}
                        />
                        <div className="xl:col-span-2">
                            <ApproachCard
                                approach="c"
                                family={family}
                                lang={lang}
                            />
                        </div>
                    </div>
                ) : (
                    <ApproachCard
                        approach={mode}
                        family={family}
                        lang={lang}
                        onFullscreen={
                            mode === 'a' || mode === 'b'
                                ? () => enterFullscreen(mode)
                                : undefined
                        }
                    />
                )}
            </section>

            {/* Copy sheet EN + DE side by side */}
            <Card>
                <CardHeader>
                    <CardTitle>
                        {lang === 'de'
                            ? 'Textvorschläge (EN + DE) — Was passiert ist, nächster Schritt, Link'
                            : 'Proposed copy (EN + DE) — What happened, next step, recovery link'}
                    </CardTitle>
                    <CardDescription>
                        {lang === 'de'
                            ? 'Einheitliches Muster für Seite, Toast und Inline-Hinweis.'
                            : 'One message formula across error page, toast notice, and inline prevention.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {(['en', 'de'] as const).map((localeKey) => (
                            <div
                                key={localeKey}
                                className="space-y-2 rounded-lg border p-3.5 text-sm"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <Badge variant="outline">
                                        {localeKey === 'en'
                                            ? 'English'
                                            : 'Deutsch (Sie)'}
                                    </Badge>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {family.statuses}
                                    </span>
                                </div>
                                <div className="space-y-1">
                                    <p className="font-medium text-foreground">
                                        {family.page.title[localeKey]}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {family.page.description[localeKey]}
                                    </p>
                                </div>
                                <div className="space-y-1 border-t pt-2 text-xs text-muted-foreground">
                                    <p>
                                        <span className="font-medium text-foreground">
                                            Toast:{' '}
                                        </span>
                                        {family.inPlace.toastMessage[localeKey]}
                                    </p>
                                    {family.inPlace.preventReason && (
                                        <p>
                                            <span className="font-medium text-foreground">
                                                {localeKey === 'de'
                                                    ? 'Vorab-Hinweis: '
                                                    : 'Prevented reason: '}
                                            </span>
                                            {
                                                family.inPlace.preventReason[
                                                    localeKey
                                                ]
                                            }
                                        </p>
                                    )}
                                    <p>
                                        <span className="font-medium text-foreground">
                                            {localeKey === 'de'
                                                ? 'Aktion: '
                                                : 'Recovery: '}
                                        </span>
                                        {family.page.recoveryLabel[localeKey]}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Full matrix summary */}
            <section className="space-y-3">
                <SectionHeading>
                    {lang === 'de'
                        ? '3. Übersicht aller 12 Fallgruppen'
                        : '3. All 12 case families at a glance'}
                </SectionHeading>
                <div className="hidden overflow-hidden rounded-lg border md:block">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {lang === 'de' ? 'Gruppe' : 'Family'}
                                </TableHead>
                                <TableHead>HTTP</TableHead>
                                <TableHead>
                                    {lang === 'de'
                                        ? 'Erw. / Unerw. / API'
                                        : 'Exp / Unexp / API'}
                                </TableHead>
                                <TableHead>
                                    {lang === 'de'
                                        ? 'Empfohlener Kanal'
                                        : 'Recommended channel'}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ERROR_FAMILIES.map((item) => (
                                <TableRow
                                    key={item.id}
                                    data-state={
                                        item.id === family.id
                                            ? 'selected'
                                            : undefined
                                    }
                                >
                                    <TableCell column="name">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="xs"
                                            onClick={() =>
                                                selectFamily(item.id)
                                            }
                                        >
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {item.code}
                                            </span>
                                            <span>{pick(item.name, lang)}</span>
                                        </Button>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {item.statuses}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {item.counts.expected} /{' '}
                                        {item.counts.unexpected} /{' '}
                                        {item.counts.apiOnly}
                                    </TableCell>
                                    <TableCell column="primary">
                                        <span className="text-cell">
                                            {pick(
                                                APPROACH_LABELS[
                                                    item.recommended
                                                ],
                                                lang,
                                            )}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <div className="divide-y overflow-hidden rounded-lg border md:hidden">
                    {ERROR_FAMILIES.map((item) => (
                        <div
                            key={item.id}
                            className="space-y-1.5 px-4 py-3 text-sm"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    className="max-w-full min-w-0"
                                    onClick={() => selectFamily(item.id)}
                                >
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {item.code}
                                    </span>
                                    <span className="truncate">
                                        {pick(item.name, lang)}
                                    </span>
                                </Button>
                                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                    {item.statusSample}
                                </span>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>
                                    {pick(
                                        APPROACH_LABELS[item.recommended],
                                        lang,
                                    )}
                                </span>
                                <span className="font-mono">
                                    {item.counts.expected} /{' '}
                                    {item.counts.unexpected} /{' '}
                                    {item.counts.apiOnly}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </PageContainer>
    );
}
