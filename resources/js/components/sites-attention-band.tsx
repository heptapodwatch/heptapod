import { EASE_OUT } from '@/lib/motion';
import { TruncatedText } from '@/components/truncated-text';
import { SectionHeading } from '@/components/section-heading';
import { TextLink, TextLinkButton } from '@/components/text-link';
import { SiteAvatar } from '@/components/site-avatar';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SitesStateRing } from '@/components/sites-state-ring';
import { StateSentence } from '@/components/foundation-primitives';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useFlip } from '@/hooks/use-flip';
import { cn } from '@/lib/utils';
import { useFormat } from '@/hooks/use-format';
import { useCheckHeadline } from '@/hooks/use-check-headline';
import { isAttentionState, runState, stateCountOrder } from '@/lib/status';
import { show as incidentShow } from '@/routes/incidents';
import { team as teamNotifications } from '@/routes/settings/notifications';
import { index as sitesIndex, show as siteShow } from '@/routes/sites';

/** Rows the band shows; SitesReader sends exactly these. */
const ATTENTION_SLOTS = 5;
/** At 640 px and below the band shows three rows; the rest goes to the overflow link. */
const MOBILE_ATTENTION_SLOTS = 3;
const EXPAND_MS = 300;
/** The last row's exit (useFlip's ghost fade) finishes before the band collapses. */
const EXIT_MS = 220;

function motionAllowed(): boolean {
    return (
        !document.hidden &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Health headline, state ring and attention rows above the Sites list. The list's own All/Attention filter is
 * separate; only the "Show all" link changes it, through onShowAll.
 *
 * Unlike the list, the band applies live changes at once: entries and exits move with
 * the shared FLIP in the same commit as the headline and ring. Its height follows the visible rows.
 */
export function SitesAttentionBand({
    tiles: liveTiles,
    needsAttention: liveAttention,
    needsAttentionTotal: liveTotal,
    clients,
    onShowAll,
}: {
    tiles: Heptapod.Data.Ui.DashboardTilesData;
    needsAttention: Heptapod.Data.Ui.SiteRowData[];
    needsAttentionTotal: number;
    clients: Heptapod.Data.Ui.ClientData[];
    onShowAll: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const checkHeadline = useCheckHeadline();
    const { props } = usePage();
    const live = props.overallHealth;
    if (!live) throw new Error('Sites requires shared monitoring health.');
    // One snapshot of everything the band shows, swapped inside flip() so rows, headline and ring change together.
    const [snapshot, setSnapshot] = useState({
        tiles: liveTiles,
        needsAttention: liveAttention,
        needsAttentionTotal: liveTotal,
        health: live,
    });
    const { ref: rowsRef, flip } = useFlip<HTMLDivElement>();
    useLayoutEffect(() => {
        if (
            snapshot.tiles !== liveTiles ||
            snapshot.needsAttention !== liveAttention ||
            snapshot.needsAttentionTotal !== liveTotal ||
            snapshot.health !== live
        )
            flip(() =>
                setSnapshot({
                    tiles: liveTiles,
                    needsAttention: liveAttention,
                    needsAttentionTotal: liveTotal,
                    health: live,
                }),
            );
    });
    // The one allowed shift (heptapod-ui-rules): calm <-> first entry. The band stays mounted at height 0 while
    // calm, opens over 300 ms when the first site needs attention, and closes after the last row's exit.
    // The height itself changes in one step; what the eye sees is the content below sliding by transform and the
    // band revealed by clip-path. Both run on the compositor: a WAAPI height animation dropped most of its
    // frames here, because the Sites list re-renders in the same frames (fix round 1 measurement).
    const rootRef = useRef<HTMLDivElement>(null);
    const collapseRef = useRef<HTMLDivElement>(null);
    const wasOpen = useRef<boolean | null>(null);
    const pendingCollapse = useRef<number | undefined>(undefined);
    const open = snapshot.needsAttentionTotal > 0;
    useLayoutEffect(() => {
        const band = collapseRef.current;
        if (band === null) return;
        const previous = wasOpen.current;
        wasOpen.current = open;
        if (previous === open) return;
        window.clearTimeout(pendingCollapse.current);
        const animate = previous !== null && motionAllowed();
        const below = () => {
            const elements: HTMLElement[] = [];
            for (
                let next = rootRef.current?.nextElementSibling;
                next instanceof HTMLElement;
                next = next.nextElementSibling
            )
                elements.push(next);
            return elements;
        };
        const slide = (from: number) => {
            for (const element of below())
                element.animate(
                    {
                        transform: [`translateY(${from}px)`, 'translateY(0px)'],
                    },
                    { duration: EXPAND_MS, easing: EASE_OUT },
                );
        };
        if (open) {
            const before = band.getBoundingClientRect().height;
            band.style.height = '';
            if (!animate) return;
            const delta = band.getBoundingClientRect().height - before;
            band.animate(
                { clipPath: ['inset(0 0 100% 0)', 'inset(0 0 0% 0)'] },
                { duration: EXPAND_MS, easing: EASE_OUT },
            );
            slide(-delta);
            return;
        }
        const collapse = () => {
            const before = band.getBoundingClientRect().height;
            band.style.height = '0px';
            if (motionAllowed()) slide(before);
        };
        if (animate)
            pendingCollapse.current = window.setTimeout(collapse, EXIT_MS);
        else collapse();
    });
    useEffect(() => () => window.clearTimeout(pendingCollapse.current), []);
    const { tiles, needsAttention, needsAttentionTotal, health } = snapshot;
    const shown = needsAttention.slice(0, ATTENTION_SLOTS);
    const overflow = needsAttentionTotal - shown.length;
    const mobileOverflow =
        needsAttentionTotal - Math.min(shown.length, MOBILE_ATTENTION_SLOTS);
    if (tiles.sites === 0) return null;

    const down = needsAttention.find((site) => site.state === 'down');
    const active = health.active_checks;
    const stale = health.status === 'down' && active > 0;
    const degraded = health.status === 'warn';
    const healthy =
        health.status === 'ok' &&
        tiles.ok === tiles.sites &&
        tiles.down === 0 &&
        tiles.open_incidents === 0 &&
        tiles.warning === 0 &&
        tiles.check_errors === 0 &&
        tiles.failed === 0 &&
        tiles.pending === 0;
    const sentence =
        active === 0
            ? t('No sites are being checked.')
            : stale
              ? t('We cannot confirm the current state.')
              : degraded
                ? t('Monitoring degraded')
                : down
                  ? tiles.down > 1
                      ? t('{{count}} sites are down.', {
                            count: tiles.down,
                            formattedCount: format.number(tiles.down),
                        })
                      : t('{{site}} is down.', { site: down.name })
                  : healthy
                    ? t('All {{count}} sites are OK.', {
                          count: tiles.sites,
                          formattedCount: format.number(tiles.sites),
                      })
                    : needsAttentionTotal === 0
                      ? t('{{count}} of {{total}} sites are OK.', {
                            count: tiles.sites,
                            formattedCount: format.number(tiles.ok),
                            total: format.number(tiles.sites),
                        })
                      : t('{{count}} sites need attention.', {
                            count: needsAttentionTotal,
                            formattedCount: format.number(needsAttentionTotal),
                        });
    // Sites that are neither OK nor in the band are named by state, so none is silent (also next to a full band).
    const notOk = stateCountOrder
        .filter(
            (
                state,
            ): state is
                | 'pending'
                | 'maintenance'
                | 'paused'
                | 'disabled'
                | 'unavailable' =>
                state === 'pending' ||
                state === 'maintenance' ||
                state === 'paused' ||
                state === 'disabled' ||
                state === 'unavailable',
        )
        .filter((state) => tiles[state] > 0)
        .map((state) =>
            t(`sites.not_ok_${state}`, { count: format.number(tiles[state]) }),
        )
        .join(' · ');
    const detail = stale
        ? t('Last known result: {{time}}. These states may be stale.', {
              time: health.last_result_at
                  ? format.relative(health.last_result_at)
                  : '—',
          })
        : [
              degraded && needsAttentionTotal > 0
                  ? t('{{count}} sites need attention.', {
                        count: needsAttentionTotal,
                        formattedCount: format.number(needsAttentionTotal),
                    })
                  : '',
              active > 0 ? notOk : '',
          ]
              .filter(Boolean)
              .join(' · ') || undefined;

    return (
        <div ref={rootRef} className="min-w-0 space-y-4">
            <div className="flex items-start gap-3">
                <SitesStateRing tiles={tiles} />
                <div className="min-w-0 flex-1">
                    <StateSentence
                        state={
                            stale
                                ? 'check-error'
                                : active === 0
                                  ? 'paused'
                                  : degraded
                                    ? 'warning'
                                    : down
                                      ? 'down'
                                      : (needsAttention[0]?.state ??
                                        // Paused, pending, maintenance and off sites live in the detail line, never as Warning.
                                        (needsAttentionTotal === 0 &&
                                        health.notifications_covered
                                            ? 'ok'
                                            : 'warning'))
                        }
                        // A fixed number of lines (two below sm, one from sm) so a long site name never moves the band.
                        sentence={
                            <TruncatedText content={sentence}>
                                <span className="line-clamp-2 min-h-[2lh] basis-full sm:line-clamp-1 sm:min-h-0 sm:min-w-0 sm:flex-1 sm:basis-0">
                                    {sentence}
                                </span>
                            </TruncatedText>
                        }
                        // Always one detail line (empty when there is nothing to say), so it never moves the band.
                        detail={
                            <TruncatedText content={detail}>
                                <span className="block truncate">
                                    {detail ?? '\u00a0'}
                                </span>
                            </TruncatedText>
                        }
                    />
                </div>
            </div>
            {!stale && active > 0 && !health.notifications_covered && (
                <Alert
                    tone="danger"
                    action={
                        props.auth.canManageChannels ? (
                            <TextLink href={teamNotifications()}>
                                {t('add_site.no_rules.action')}
                            </TextLink>
                        ) : undefined
                    }
                >
                    <AlertTitle>
                        {t('No monitored check has an alert route.')}
                    </AlertTitle>
                    <AlertDescription>
                        {t(
                            'A failure sends no alert until a channel hears about outages or failures.',
                        )}
                    </AlertDescription>
                </Alert>
            )}
            {stale && (
                <Alert tone="danger">
                    <AlertTitle>{t('Monitoring is not current.')}</AlertTitle>
                    <AlertDescription>
                        {t('Last dispatcher heartbeat: {{time}}.', {
                            time: health.dispatcher_at
                                ? format.relative(health.dispatcher_at)
                                : '—',
                        })}
                    </AlertDescription>
                </Alert>
            )}
            {active === 0 ? (
                <Alert
                    tone="warning"
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={sitesIndex.url({
                                    query: { 'filter[state]': 'paused' },
                                })}
                            >
                                {t('Pick sites')}
                            </Link>
                        </Button>
                    }
                >
                    <AlertTitle>
                        {t('Paused is not the same as fine.')}
                    </AlertTitle>
                    <AlertDescription>
                        {t('Select paused sites to resume monitoring.')}
                    </AlertDescription>
                </Alert>
            ) : (
                <div
                    ref={collapseRef}
                    data-attention-band
                    className="overflow-hidden"
                    aria-hidden={open ? undefined : true}
                    inert={!open}
                >
                    <section
                        className="min-w-0 space-y-3"
                        aria-label={t('Needs attention')}
                    >
                        {/* One line at every width: the overflow link truncates, so the header never wraps. */}
                        <div className="flex h-7 min-w-0 items-center justify-between gap-3">
                            <SectionHeading className="shrink-0">
                                {t('Needs attention')}
                            </SectionHeading>
                            <OverflowLink
                                className="max-[641px]:hidden"
                                count={overflow}
                                total={needsAttentionTotal}
                                onClick={onShowAll}
                            />
                            <OverflowLink
                                className="min-[641px]:hidden"
                                count={mobileOverflow}
                                total={needsAttentionTotal}
                                onClick={onShowAll}
                            />
                        </div>
                        <div
                            ref={rowsRef}
                            className="@container/table relative max-h-[32rem] overflow-x-hidden overflow-y-auto rounded-lg border"
                        >
                            <ListHeader className="grid-cols-[8.5rem_minmax(0,1fr)_7rem_8rem] sm:grid">
                                <span>{t('State')}</span>
                                <span>{t('What')}</span>
                                <span>{t('When')}</span>
                                <span>{t('Incident')}</span>
                            </ListHeader>
                            <div>
                                {shown.map((site, index) => {
                                    const check =
                                        (site.state === 'down'
                                            ? site.checks.find(
                                                  (item) =>
                                                      item.key === 'uptime',
                                              )
                                            : undefined) ??
                                        site.checks.find(
                                            (item) =>
                                                item.open_incident_id !==
                                                    null &&
                                                item.uptime_parent_incident_id ===
                                                    null,
                                        ) ??
                                        site.checks.find(
                                            (item) =>
                                                item.open_incident_id !== null,
                                        ) ??
                                        site.checks.find((item) =>
                                            isAttentionState(
                                                runState(item.key, item.result),
                                            ),
                                        );
                                    const affectedChecks =
                                        check?.uptime_parent_incident_id != null
                                            ? site.checks
                                                  .filter(
                                                      (item) =>
                                                          item.uptime_parent_incident_id ===
                                                          check.uptime_parent_incident_id,
                                                  )
                                                  .map((item) => item.key)
                                            : (check?.affected_checks ?? []);
                                    const affectedText =
                                        check && affectedChecks.length > 0
                                            ? t(
                                                  check.held_after_recovery
                                                      ? 'notifications.rechecking'
                                                      : 'notifications.also_affected',
                                                  {
                                                      checks: affectedChecks
                                                          .map((key) =>
                                                              t(
                                                                  `checks.${key}.label`,
                                                              ),
                                                          )
                                                          .join(', '),
                                                  },
                                              )
                                            : undefined;
                                    const incidentOpen =
                                        check?.open_incident_id != null;
                                    const attentionTime = incidentOpen
                                        ? check.open_incident_started_at
                                        : site.checked_at;
                                    return (
                                        <ListRow
                                            key={site.id}
                                            data-flip-key={`site:${site.id}`}
                                            className={cn(
                                                'sm:grid-cols-[8.5rem_minmax(0,1fr)_7rem_8rem] sm:items-center',
                                                index >=
                                                    MOBILE_ATTENTION_SLOTS &&
                                                    'max-[641px]:hidden',
                                            )}
                                        >
                                            <StatusBadge state={site.state} />
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {check
                                                        ? format.issueHeadline(
                                                              {
                                                                  ...check,
                                                                  check: check.key,
                                                                  fallback:
                                                                      checkHeadline(
                                                                          check,
                                                                      ),
                                                              },
                                                              true,
                                                          )
                                                        : site.name}
                                                </p>
                                                {affectedText && (
                                                    <TruncatedText
                                                        content={affectedText}
                                                    >
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {affectedText}
                                                        </p>
                                                    </TruncatedText>
                                                )}
                                                <TextLink
                                                    variant="secondary"
                                                    href={siteShow.url(site.id)}
                                                    truncate
                                                    title={site.name}
                                                    className="max-w-full min-w-0"
                                                    icon={
                                                        <SiteAvatar
                                                            name={site.name}
                                                            iconUrl={
                                                                site.identity
                                                                    .icon_url
                                                            }
                                                            iconBacking={
                                                                site.identity
                                                                    .icon_backing
                                                            }
                                                        />
                                                    }
                                                >
                                                    {
                                                        clients.find(
                                                            (client) =>
                                                                client.id ===
                                                                site.client_id,
                                                        )?.name
                                                    }{' '}
                                                    · {site.name}
                                                </TextLink>
                                            </div>
                                            {attentionTime ? (
                                                <MonitoringTime
                                                    value={attentionTime}
                                                    mode="since"
                                                    prefix={t(
                                                        incidentOpen
                                                            ? 'Since'
                                                            : 'Checked',
                                                    )}
                                                    className="font-mono text-xs text-muted-foreground"
                                                />
                                            ) : (
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {t(
                                                        incidentOpen
                                                            ? 'Start time unavailable'
                                                            : 'Awaiting first result',
                                                    )}
                                                </span>
                                            )}
                                            {check?.open_incident_id != null ? (
                                                <TextLink
                                                    className="text-xs"
                                                    href={incidentShow.url(
                                                        check.open_incident_id,
                                                    )}
                                                >
                                                    {t('Open incident')}
                                                </TextLink>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    {t('No incident')}
                                                </span>
                                            )}
                                        </ListRow>
                                    );
                                })}
                            </div>
                        </div>
                    </section>
                </div>
            )}
        </div>
    );
}

/** "Show all N" for the sites beyond the band's slots; it opens the list filtered to Needs attention. */
function OverflowLink({
    className,
    count,
    total,
    onClick,
}: {
    className: string;
    count: number;
    total: number;
    onClick: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    if (count <= 0) return null;
    return (
        <TextLinkButton
            truncate
            className={cn('min-w-0 shrink', className)}
            onClick={onClick}
        >
            {t('sites.attention_show_all', {
                count: total,
                formattedCount: format.number(total),
            })}
        </TextLinkButton>
    );
}
