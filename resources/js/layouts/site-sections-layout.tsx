import { Link, router } from '@inertiajs/react';
import {
    ChevronsUpDown,
    ExternalLink,
    LayoutGrid,
    Settings,
} from 'lucide-react';
import { useEffect, useState, type PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge, StatusDot } from '@/components/monitoring';
import { PageContainer } from '@/components/page-container';
import { SiteAvatar } from '@/components/site-avatar';
import { siteCheckOrder } from '@/components/site-checks-list';
import { TextLink } from '@/components/text-link';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useFormat } from '@/hooks/use-format';
import { checkShortState } from '@/lib/check-short-state';
import {
    bySeverity,
    checkState,
    isAttentionState,
    isSnoozed,
    statusLabels,
} from '@/lib/status';
import type { SiteState } from '@/types/monitoring';
import { cn } from '@/lib/utils';
import { show as clientShow } from '@/routes/clients';
import { settings as siteSettings, show as siteShow } from '@/routes/sites';
import { show as checkShow } from '@/routes/sites/checks';

type Site = Heptapod.Data.Ui.SiteRowData;
type Check = Heptapod.Data.Ui.CheckStripData;

/** The rail's two groups as check IDs, in display order. */
type RailPlacement = { attention: number[]; other: number[] };

/** Down, Failed, Check error, Warning, unavailable or without current data (stale or freshness-suppressed). */
function needsAttention(check: Check): boolean {
    if (!check.enabled) return false;
    const state = checkState(check);
    return (
        isAttentionState(state) ||
        state === 'unavailable' ||
        check.not_fresh ||
        check.freshness_suppressed_by !== null
    );
}

function canonicalOrder(check: Check): number {
    return siteCheckOrder.indexOf(check.key);
}

function byAttention(left: Check, right: Check): number {
    return (
        bySeverity(checkState(left), checkState(right)) ||
        canonicalOrder(left) - canonicalOrder(right) ||
        left.id - right.id
    );
}

function byCanonical(left: Check, right: Check): number {
    return (
        Number(!left.enabled) - Number(!right.enabled) ||
        canonicalOrder(left) - canonicalOrder(right) ||
        left.id - right.id
    );
}

function placeChecks(checks: Check[]): RailPlacement {
    return {
        attention: checks
            .filter(needsAttention)
            .toSorted(byAttention)
            .map((check) => check.id),
        other: checks
            .filter((check) => !needsAttention(check))
            .toSorted(byCanonical)
            .map((check) => check.id),
    };
}

/** Takes the moved checks out of their places and inserts each at its sorted position in its current group; nothing else moves. */
function moveChecks(
    placement: RailPlacement,
    moved: Check[],
    byId: Map<number, Check>,
): RailPlacement {
    const ids = new Set(moved.map((check) => check.id));
    const next = {
        attention: placement.attention.filter((id) => !ids.has(id)),
        other: placement.other.filter((id) => !ids.has(id)),
    };
    for (const check of moved) {
        const attention = needsAttention(check);
        const group = attention ? next.attention : next.other;
        const compare = attention ? byAttention : byCanonical;
        const index = group.findIndex((id) => {
            const placed = byId.get(id);
            return placed !== undefined && compare(check, placed) < 0;
        });
        group.splice(index === -1 ? group.length : index, 0, check.id);
    }
    return next;
}

/** The check a write visit changed: any non-GET visit to one of this site's check routes, except a run or its cancel, whose result arrives later. */
function writtenCheckId(siteId: number, url: URL): number | null {
    const match = /^\/sites\/(\d+)\/checks\/(\d+)(\/[^/]+)?/.exec(url.pathname);
    if (!match || Number(match[1]) !== siteId) return null;
    return match[3] === '/run' || match[3] === '/cancel'
        ? null
        : Number(match[2]);
}

/** The new page's heading takes focus after a drawer visit: the drawer and its trigger belonged to the old page. */
function focusHeading(): void {
    requestAnimationFrame(() => {
        const heading = document.querySelector<HTMLElement>(
            '[data-page-header] h1',
        );
        if (!heading) return;
        heading.tabIndex = -1;
        heading.focus({ preventScroll: true });
    });
}

/** The status shape of one rail item: a check without current data shows the check-error ring. */
function railDotState(check: Check, snoozed: boolean): SiteState {
    if (snoozed) return check.state;
    const state = checkState(check);
    return check.not_fresh && !check.freshness_suppressed_by && state === 'ok'
        ? 'check-error'
        : state;
}

/** The check's status shape; hidden from screen readers when the short state beside it already says the same word. */
function RailDot({
    check,
    shortState,
    now,
    className,
}: {
    check: Check;
    shortState: string;
    now: number;
    className?: string;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const snoozed = isSnoozed(check, now);
    const state = railDotState(check, snoozed);
    const label = snoozed
        ? t('Snoozed until {{time}}', {
              time: format.timestamp(check.snoozed_until!),
          })
        : t(
              check.freshness_suppressed_by
                  ? 'No current data'
                  : check.not_fresh
                    ? 'freshness.not_fresh'
                    : statusLabels[state],
          );
    return (
        <span
            className={cn('inline-flex shrink-0', className)}
            aria-hidden={label === shortState || undefined}
        >
            <StatusDot state={state} snoozed={snoozed} label={label} />
        </span>
    );
}

function SiteIdentity({
    site,
    client,
    host,
    maintenance,
}: {
    site: Site;
    client: Heptapod.Data.Ui.ClientData | null;
    host: string;
    maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null;
}) {
    const { t } = useTranslation();
    const paused = site.identity.state === 'paused';
    const maintenanceActive = maintenance?.state === 'active';

    return (
        <div className="space-y-1.5">
            {client && (
                <div className="text-xs text-muted-foreground">
                    <TextLink
                        variant="secondary"
                        href={clientShow.url(client.id)}
                        prefetch
                    >
                        {client.name}
                    </TextLink>
                </div>
            )}
            <div className="flex items-center gap-2">
                <SiteAvatar
                    name={site.identity.name}
                    iconUrl={site.identity.icon_url}
                    iconBacking={site.identity.icon_backing}
                />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1">
                        <TruncatedText content={site.identity.name}>
                            <span className="truncate text-section">
                                {site.identity.name}
                            </span>
                        </TruncatedText>
                        <TooltipHint content={site.identity.url}>
                            <Button
                                variant="ghost"
                                size="icon-xs"
                                asChild
                                className="shrink-0 text-muted-foreground"
                            >
                                <a
                                    href={site.identity.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-label={t('Open site')}
                                >
                                    <ExternalLink className="size-3.5" />
                                </a>
                            </Button>
                        </TooltipHint>
                    </div>
                    <p className="truncate font-mono text-xs text-muted-foreground">
                        {host}
                    </p>
                </div>
            </div>
            {(paused || maintenanceActive) && (
                <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                    {paused && <StatusBadge state="paused" />}
                    {maintenanceActive && <StatusBadge state="maintenance" />}
                </div>
            )}
        </div>
    );
}

/**
 * The frame of every site page (Overview, each check, Settings): site identity and the vertical section rail on the
 * left (a drawer below lg), the section on the right. The rail never re-sorts under the user: groups and order are taken
 * when the page opens; after this tab's own successful write to a check only that check moves; live updates and new
 * arrivals (placed once, at the end of their group) change nothing else.
 */
export function SiteSectionsLayout({
    site,
    client,
    checks,
    value,
    canOpenSettings,
    maintenance,
    children,
}: PropsWithChildren<{
    site: Site;
    client: Heptapod.Data.Ui.ClientData | null;
    /** The listed checks: every enabled one, plus the viewed one when it is turned off. */
    checks: Check[];
    /** The active section: 'overview', 'settings' or the check ID. */
    value: string;
    canOpenSettings: boolean;
    maintenance: Heptapod.Data.Ui.MaintenanceSiteHeaderData | null;
}>) {
    const { t } = useTranslation();
    const format = useFormat();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const byId = new Map(checks.map((check) => [check.id, check]));
    // Inertia remounts the page on navigation, so this is taken once per visit.
    const [placement, setPlacement] = useState(() => placeChecks(checks));
    // Checks this tab changed through a successful write; they move once the response has rendered.
    const [ownChanges, setOwnChanges] = useState<number[]>([]);
    useEffect(() => {
        const writes = new Map<string, number>();
        const stopBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (['get', 'query'].includes(visit.method)) return;
            const checkId = writtenCheckId(site.id, visit.url);
            if (checkId !== null) writes.set(visit.id, checkId);
        });
        const stopSuccess = router.on('success', (event) => {
            const checkId =
                event.detail.visitId === undefined
                    ? undefined
                    : writes.get(event.detail.visitId);
            if (checkId === undefined) return;
            writes.delete(event.detail.visitId!);
            setOwnChanges((ids) => [...ids, checkId]);
        });
        return () => {
            stopBefore();
            stopSuccess();
        };
    }, [site.id]);
    const placed = new Set([...placement.attention, ...placement.other]);
    const arrivals = checks.filter((check) => !placed.has(check.id));
    if (ownChanges.length > 0 || arrivals.length > 0) {
        const moved = placeChecks(arrivals);
        const next = moveChecks(
            placement,
            checks.filter((check) => ownChanges.includes(check.id)),
            byId,
        );
        setPlacement({
            attention: [...next.attention, ...moved.attention],
            other: [...next.other, ...moved.other],
        });
        setOwnChanges([]);
    }
    const now = Date.now();
    const inOrder = (ids: number[]) => ids.flatMap((id) => byId.get(id) ?? []);
    const attentionChecks = inOrder(placement.attention);
    const otherChecks = inOrder(placement.other);

    const activeCheck = byId.get(Number(value));
    const activeShortState = activeCheck
        ? checkShortState(activeCheck, t, format, now)
        : null;
    const host = new URL(site.identity.url).host;

    const checkItem = (check: Check, inDrawer: boolean) => {
        const active = check === activeCheck;
        const shortState = checkShortState(check, t, format, now);
        return (
            <SidebarMenuItem key={check.id}>
                {/* Two lines (name and short state): the rail's named height exception. */}
                <SidebarMenuButton
                    asChild
                    isActive={active}
                    className={cn(
                        'h-auto items-start gap-2.5 py-1.5 leading-normal',
                        !check.enabled && 'text-muted-foreground',
                    )}
                >
                    <Link
                        href={checkShow.url({ site: site.id, check: check.id })}
                        aria-current={active ? 'page' : undefined}
                        prefetch
                        {...drawerLink(active, inDrawer)}
                    >
                        <RailDot
                            check={check}
                            shortState={shortState}
                            now={now}
                            className="mt-1"
                        />
                        <span className="min-w-0 flex-1 space-y-0.5">
                            <span className="block truncate text-cell leading-tight">
                                {t(check.label_key)}
                            </span>
                            <span className="block truncate text-xs leading-tight text-muted-foreground tabular-nums">
                                {shortState}
                            </span>
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    };

    const sectionItem = (
        section: 'overview' | 'settings',
        inDrawer: boolean,
    ) => (
        <SidebarMenuItem>
            <SidebarMenuButton asChild isActive={value === section}>
                <Link
                    href={
                        section === 'overview'
                            ? siteShow.url(site.id)
                            : siteSettings.url(site.id)
                    }
                    aria-current={value === section ? 'page' : undefined}
                    prefetch
                    {...drawerLink(value === section, inDrawer)}
                >
                    {section === 'overview' ? (
                        <LayoutGrid aria-hidden />
                    ) : (
                        <Settings aria-hidden />
                    )}
                    <span>
                        {t(section === 'overview' ? 'Overview' : 'Settings')}
                    </span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );

    /**
     * In the drawer: the current section only closes it (focus returns to the switcher); another section closes it and,
     * once that visit has succeeded, focuses the new page's heading.
     */
    const drawerLink = (current: boolean, inDrawer: boolean) =>
        inDrawer
            ? {
                  onClick: (event: { preventDefault: () => void }) => {
                      if (current) event.preventDefault();
                      setDrawerOpen(false);
                  },
                  onSuccess: current ? undefined : focusHeading,
              }
            : {};

    const navigation = (inDrawer: boolean) => (
        <nav aria-label={t('Site sections')} className="space-y-1">
            <SidebarGroup className="py-0 pb-2">
                <SidebarMenu>{sectionItem('overview', inDrawer)}</SidebarMenu>
            </SidebarGroup>
            {attentionChecks.length > 0 && (
                <SidebarGroup className="py-0 pb-3">
                    <SidebarGroupLabel>
                        <span>
                            {t('Needs attention')} ·{' '}
                            <span className="font-mono tabular-nums">
                                {format.number(attentionChecks.length)}
                            </span>
                        </span>
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {attentionChecks.map((check) =>
                            checkItem(check, inDrawer),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            )}
            {otherChecks.length > 0 && (
                <SidebarGroup className="py-0 pb-3">
                    <SidebarGroupLabel>
                        {t(
                            attentionChecks.length > 0
                                ? 'site_sections.other_checks'
                                : 'Checks',
                        )}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {otherChecks.map((check) => checkItem(check, inDrawer))}
                    </SidebarMenu>
                </SidebarGroup>
            )}
            {canOpenSettings && (
                <SidebarGroup className="border-t border-border-soft py-0 pt-2">
                    <SidebarMenu>
                        {sectionItem('settings', inDrawer)}
                    </SidebarMenu>
                </SidebarGroup>
            )}
        </nav>
    );

    return (
        <PageContainer className="lg:flex-row lg:items-start lg:gap-10">
            <div className="space-y-3 lg:hidden">
                <SiteIdentity
                    site={site}
                    client={client}
                    host={host}
                    maintenance={maintenance}
                />
                {/* The section drawer: navigation, not a form, so not a SheetForm (named in check-ui-primitives). */}
                <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
                    {/* The trigger gets focus back when the drawer closes. */}
                    <SheetTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full justify-between px-3"
                        >
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="sr-only">
                                    {t('site_sections.choose_section')}:
                                </span>
                                {activeCheck ? (
                                    <RailDot
                                        check={activeCheck}
                                        shortState={activeShortState!}
                                        now={now}
                                    />
                                ) : value === 'settings' ? (
                                    <Settings
                                        aria-hidden
                                        className="size-4 shrink-0"
                                    />
                                ) : (
                                    <LayoutGrid
                                        aria-hidden
                                        className="size-4 shrink-0"
                                    />
                                )}
                                <span className="truncate">
                                    {activeCheck
                                        ? t(activeCheck.label_key)
                                        : t(
                                              value === 'settings'
                                                  ? 'Settings'
                                                  : 'Overview',
                                          )}
                                </span>
                                {activeCheck && (
                                    <span className="truncate text-xs text-muted-foreground tabular-nums">
                                        · {activeShortState}
                                    </span>
                                )}
                            </span>
                            <span className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                                {attentionChecks.length > 0 && (
                                    <Badge
                                        variant="outline"
                                        className="font-mono tabular-nums"
                                        aria-label={t(
                                            'site_sections.attention_count',
                                            {
                                                count: attentionChecks.length,
                                                formattedCount: format.number(
                                                    attentionChecks.length,
                                                ),
                                            },
                                        )}
                                    >
                                        {format.number(attentionChecks.length)}
                                    </Badge>
                                )}
                                <ChevronsUpDown
                                    aria-hidden
                                    className="size-4"
                                />
                            </span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        className="w-[300px] overflow-y-auto p-4 sm:w-[320px]"
                    >
                        <SheetHeader className="p-0 pb-3">
                            <SheetTitle>{site.identity.name}</SheetTitle>
                            <SheetDescription className="font-mono text-xs">
                                {host}
                            </SheetDescription>
                        </SheetHeader>
                        {navigation(true)}
                    </SheetContent>
                </Sheet>
            </div>

            <aside className="-mx-2 hidden w-[248px] shrink-0 lg:block">
                <div className="mb-3 border-b border-border-soft px-2 pb-3">
                    <SiteIdentity
                        site={site}
                        client={client}
                        host={host}
                        maintenance={maintenance}
                    />
                </div>
                {navigation(false)}
            </aside>

            <div className="min-w-0 flex-1 space-y-6">{children}</div>
        </PageContainer>
    );
}
