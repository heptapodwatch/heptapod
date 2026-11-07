import type {
    DesignPageProps,
    ClientDto,
    DesignIncidentDto,
} from '@/types/design';
import type {
    CheckDto,
    CheckKey,
    EventDto,
    SiteDto,
    SiteState,
    UptimeBucketDto,
} from '@/types/monitoring';

import { checkTypes } from '@/lib/check-types';
import { isAttentionState, runState } from '@/lib/status';
const checkNames = Object.fromEntries(
    Object.entries(checkTypes).map(([key, type]) => [key, type.label]),
) as Record<CheckKey, string>;
const checkIconIds: Record<CheckKey, string> = {
    uptime: 'activity',
    performance: 'timer',
    certificate_health: 'shield-check',
    domain: 'globe',
    dns: 'network',
    sitemap: 'map',
    broken_links: 'unlink',
    mixed_content: 'shield-alert',
    lighthouse: 'gauge',
    cron: 'clock',
    application_health: 'heart-pulse',
};
const clientBranding = {
    short_name: null,
    contact_name: null,
    accent_color: null,
    avatar: {
        url: null,
        backing: 'none',
        source: 'initials',
        site_id: null,
        site_host: null,
    } satisfies Heptapod.Data.Ui.ClientAvatarData,
    avatar_site_id: null,
    archived_at: null,
    is_internal: false,
};
export const clients: ClientDto[] = [
    {
        ...clientBranding,
        id: 1,
        name: 'Acme',
        locale: 'en',
        timezone: 'Europe/London',
    },
    {
        ...clientBranding,
        id: 2,
        name: 'Northwind',
        locale: 'de',
        timezone: 'Europe/Berlin',
    },
    {
        ...clientBranding,
        id: 3,
        name: 'Contoso',
        locale: 'en',
        timezone: 'America/New_York',
    },
];
export const states: SiteState[] = [
    'ok',
    'warning',
    'down',
    'paused',
    'pending',
    'disabled',
    'failed',
    'check-error',
    'maintenance',
];

export const snapshotNow = Date.now();
const incidentStartedAt = new Date(snapshotNow - 840000).toISOString();

const names = [
    'shop.acme',
    'www.acme',
    'portal.northwind',
    'docs.northwind',
    'blog.contoso',
    'www.contoso',
    'status.acme',
    'metrics.acme',
    'help.contoso',
    'mail.northwind',
    'cdn.acme',
    'assets.contoso',
    'billing.northwind',
    'support.acme',
    'news.contoso',
    'jobs.northwind',
    'accounts.acme',
    'search.contoso',
    'media.northwind',
    'checkout.acme',
    'api.acme',
    'app.northwind',
    'api.northwind',
    'staging.contoso',
    'dev.acme',
];
const probeMetrics: Record<number, Heptapod.Addons.Uptime.UptimeMetrics[]> =
    Object.fromEntries(
        names.map((_, index) => [
            index + 1,
            Array.from({ length: 1440 }, (_, minute) => {
                const hour = Math.floor(minute / 60);
                const dns = 12 + (hour % 4),
                    tcp = 28 + (hour % 7),
                    ssl = 46 + (hour % 11),
                    ttfb =
                        140 +
                        Math.round(Math.abs(Math.sin(hour + index)) * 100) +
                        (index === 22 && hour === 14 ? 1800 : 0),
                    download = 48 + (hour % 18);
                return {
                    date: new Date(
                        Math.floor(snapshotNow / 60000) * 60000 -
                            (1439 - minute) * 60000,
                    ).toISOString(),
                    dns_time_in_seconds: dns / 1000,
                    tcp_time_in_seconds: tcp / 1000,
                    ssl_handshake_time_in_seconds: ssl / 1000,
                    remote_server_processing_time_in_seconds: ttfb / 1000,
                    download_time_in_seconds: download / 1000,
                    total_time_in_seconds:
                        (dns + tcp + ssl + ttfb + download) / 1000,
                    curl: {},
                };
            }),
        ]),
    );
export const sites: SiteDto[] = names.map((name, index) => {
    const state: SiteState =
        index === 19
            ? 'pending'
            : index < 20
              ? 'ok'
              : index === 20
                ? 'warning'
                : index === 21
                  ? 'check-error'
                  : index === 22
                    ? 'down'
                    : 'paused';
    return {
        id: index + 1,
        name: `${name}.example`,
        url: `https://${name}.example`,
        client_id: name.includes('northwind')
            ? 2
            : name.includes('contoso')
              ? 3
              : 1,
        tags: [state === 'paused' ? 'staging' : 'production'],
        state,
        identity: {
            icon_url: null,
            icon_backing: 'none',
            id: index + 1,
            name: `${name}.example`,
            url: `https://${name}.example`,
            client_id: name.includes('northwind')
                ? 2
                : name.includes('contoso')
                  ? 3
                  : 1,
            state,
        },
        uptime:
            state === 'paused' || state === 'pending'
                ? null
                : state === 'down'
                  ? 97.12
                  : state === 'warning'
                    ? 99.42
                    : 99.99,
        response:
            state === 'pending'
                ? []
                : probeMetrics[index + 1]!.filter(
                      (_, minute) => minute % 60 === 59,
                  ).map((point) => ({
                      time: point.date,
                      failure_count: 0,
                      error_count: 0,
                      warning_count: 0,
                      failure_status: null,
                      value:
                          point.total_time_in_seconds === null
                              ? null
                              : point.total_time_in_seconds * 1000,
                  })),
        checks: (Object.keys(checkNames) as CheckKey[]).map(
            (key, checkIndex): CheckDto => {
                const result: CheckDto['result'] =
                    state === 'paused' || state === 'pending'
                        ? 'pending'
                        : key === 'uptime' && state === 'down'
                          ? 'failed'
                          : key === 'certificate_health' && state === 'warning'
                            ? 'warning'
                            : key === 'cron' && index === 21
                              ? 'errored-or-timed-out'
                              : 'succeeded';
                return {
                    pending_recheck: false,
                    not_fresh: false,
                    freshness_suppressed_by: null,
                    freshness_suppression_label_key: null,
                    id: (index + 1) * 100 + checkIndex,
                    snoozed_until: null,
                    key,
                    label_key: checkNames[key],
                    icon_id: checkIconIds[key],
                    enabled: state !== 'paused',
                    state:
                        state === 'paused' ? 'paused' : runState(key, result),
                    unavailable_reason: null,
                    run: null,
                    open_incident_id:
                        key === 'uptime' && index === 22
                            ? 23
                            : key === 'cron' && index === 21
                              ? 22
                              : null,
                    uptime_parent_incident_id: null,
                    affected_checks: [],
                    uptime_recheck_after: null,
                    held_after_recovery: false,
                    dependency_headline_key: null,
                    next_run_at: null,
                    presentation: null,
                    open_incident_started_at: null,
                    open_incident_requires_complete_recovery:
                        key === 'uptime' && index === 22,
                    result,
                    ...checkSummary(key, result),
                    checked_at:
                        state === 'pending'
                            ? null
                            : new Date(snapshotNow - 12000).toISOString(),
                };
            },
        ),
        checked_at:
            state === 'pending'
                ? null
                : new Date(snapshotNow - (12 + index * 3) * 1000).toISOString(),
    };
});
const bucketStatusBySite: Record<SiteState, Heptapod.Enums.BucketStatus> = {
    ok: 'ok',
    warning: 'warning',
    down: 'failed',
    failed: 'failed',
    'check-error': 'errored',
    maintenance: 'maintenance',
    pending: 'no_data',
    paused: 'no_data',
    disabled: 'no_data',
    unavailable: 'no_data',
};
export function bucketForState(
    state: SiteState,
    date: string,
    uptime: number | null,
): UptimeBucketDto {
    const status = bucketStatusBySite[state];
    const observations =
        status === 'no_data' || status === 'maintenance' ? 0 : 1440;
    const unavailable = Math.max(
        1,
        Math.round((observations * (100 - (uptime ?? 0))) / 100),
    );
    const failed_count = status === 'failed' ? unavailable : 0;
    const errored_count = status === 'errored' ? unavailable : 0;
    const warning_count = status === 'warning' ? 1 : 0;
    const succeeded_count =
        observations - failed_count - errored_count - warning_count;
    return {
        date,
        uptime:
            observations === 0
                ? null
                : (100 * (succeeded_count + warning_count)) / observations,
        status,
        succeeded_count,
        warning_count,
        failed_count,
        errored_count,
        incident_count: failed_count || errored_count ? 1 : 0,
        failed_seconds: failed_count * 60,
    };
}
const siteHistories: Record<number, UptimeBucketDto[]> = Object.fromEntries(
    sites.map((site) => [
        site.id,
        Array.from({ length: 90 }, (_, day) => {
            const state =
                day < 3
                    ? 'pending'
                    : site.state === 'paused'
                      ? 'paused'
                      : day === 89
                        ? runState(
                              'uptime',
                              site.checks.find(
                                  (check) => check.key === 'uptime',
                              )!.result,
                          ) === 'down'
                            ? 'down'
                            : 'ok'
                        : day === 41
                          ? 'warning'
                          : 'ok';
            return bucketForState(
                state,
                new Date(
                    Math.floor(snapshotNow / 86400000) * 86400000 -
                        (89 - day) * 86400000,
                ).toISOString(),
                day < 3 || site.state === 'paused'
                    ? null
                    : day === 89 && site.state === 'down'
                      ? 97.12
                      : day === 41
                        ? 100
                        : 100,
            );
        }),
    ]),
);
function overviewHistory(
    site: SiteDto,
    state: DesignPageProps['state'] = 'populated',
): UptimeBucketDto[] {
    const history = siteHistories[site.id];
    if (!history) throw new Error(`Unknown site history ${site.id}`);
    return state === 'empty'
        ? history.map((bucket) => bucketForState('pending', bucket.date, null))
        : history;
}
const baseSite = sites[22];
if (!baseSite) throw new Error('Gallery requires the down-site fixture');
export const gallerySites: SiteDto[] = states.map((state, index) => ({
    ...baseSite,
    id: 100 + index,
    client_id: (index % 3) + 1,
    name: `${state}.heptapod.example`,
    url: `https://${state}.heptapod.example`,
    state,
    checks: baseSite.checks.map((check) => {
        const result =
            state === 'pending' || state === 'disabled' || state === 'paused'
                ? 'pending'
                : (state === 'down' && check.key === 'uptime') ||
                    (state === 'failed' && check.key === 'certificate_health')
                  ? 'failed'
                  : state === 'check-error' && check.key === 'dns'
                    ? 'errored-or-timed-out'
                    : state === 'warning' && check.key === 'application_health'
                      ? 'warning'
                      : 'succeeded';
        return {
            ...check,
            enabled: state !== 'paused' && state !== 'disabled',
            state:
                state === 'paused' || state === 'disabled'
                    ? 'paused'
                    : runState(check.key, result),
            open_incident_id: null,
            open_incident_started_at: null,
            result,
            checked_at: result === 'pending' ? null : check.checked_at,
            ...checkSummary(check.key, result),
        };
    }),
    response:
        state === 'pending' || state === 'disabled'
            ? []
            : baseSite.response.map((point, hour) => ({
                  ...point,
                  value:
                      state === 'paused'
                          ? 0
                          : state === 'down'
                            ? 500 + hour * 36 + (hour === 14 ? 1200 : 0)
                            : state === 'warning'
                              ? 430 + Math.round(Math.sin(hour) * 180)
                              : 180 +
                                index * 20 +
                                Math.round(Math.sin(hour * (index + 1)) * 22),
              })),
    uptime:
        state === 'paused' || state === 'pending' || state === 'disabled'
            ? null
            : state === 'down'
              ? 97.12
              : state === 'warning'
                ? 99.42
                : 100,
}));

export const galleryChecks: CheckDto[] = (
    Object.keys(checkNames) as CheckKey[]
).map((key, index) => {
    const result = (
        [
            'succeeded',
            'warning',
            'failed',
            'pending',
            'errored-or-timed-out',
        ] as const
    )[index % 5];
    if (!result) throw new Error('Missing gallery check result');
    return {
        pending_recheck: false,
        not_fresh: false,
        freshness_suppressed_by: null,
        freshness_suppression_label_key: null,
        id: 1000 + index,
        snoozed_until: null,
        state: runState(key, result),
        unavailable_reason: null,
        run: null,
        key,
        label_key: checkNames[key],
        icon_id: checkIconIds[key],
        enabled: true,
        open_incident_id: null,
        uptime_parent_incident_id: null,
        affected_checks: [],
        uptime_recheck_after: null,
        held_after_recovery: false,
        dependency_headline_key: null,
        next_run_at: null,
        presentation: null,
        open_incident_started_at: null,
        open_incident_requires_complete_recovery: false,
        result,
        ...checkSummary(key, result),
        checked_at: new Date(snapshotNow - 12000).toISOString(),
    };
});

function checkSummary(
    key: CheckKey,
    result: CheckDto['result'],
): Pick<CheckDto, 'summary' | 'summary_params' | 'meta' | 'meta_label'> {
    const defaults: Record<
        CheckKey,
        [string, Record<string, string | number>, string, string]
    > = {
        uptime: [
            'HTTP {{status}} · {{time}} ms',
            {
                status: result === 'failed' ? 503 : 200,
                time:
                    result === 'failed'
                        ? 3500
                        : result === 'warning'
                          ? 2400
                          : 182,
            },
            'Retries',
            result === 'failed' ? '2' : '0',
        ],
        performance: [
            'p95 {{time}} ms',
            {
                time:
                    result === 'failed'
                        ? 3500
                        : result === 'warning'
                          ? 2400
                          : 640,
            },
            'Window',
            '24h',
        ],
        certificate_health: [
            'Valid · expires in {{days}} days',
            { days: result === 'warning' ? 9 : 71 },
            'Issuer',
            "Let's Encrypt R11",
        ],
        domain: [
            'Expires {{date}}',
            { date: result === 'warning' ? '2026-10-09' : '2027-03-14' },
            'Registrar',
            'INWX',
        ],
        dns: [
            result === 'warning'
                ? '{{changes}} records changed · {{count}} records'
                : 'No changes · {{count}} records',
            { count: 12, changes: 2 },
            'Last checked',
            '',
        ],
        sitemap: [
            '{{count}} URLs · {{issues}} issues',
            { count: 412, issues: result === 'warning' ? 3 : 0 },
            'Last checked',
            '',
        ],
        broken_links: [
            '{{count}} broken ({{internal}} internal)',
            {
                count: ['failed', 'warning'].includes(result) ? 3 : 0,
                internal: ['failed', 'warning'].includes(result) ? 2 : 0,
            },
            'URLs scanned',
            '412',
        ],
        mixed_content: [
            result === 'warning'
                ? '{{count}} insecure resources'
                : 'None found',
            { count: 3 },
            'URLs scanned',
            '412',
        ],
        lighthouse: [
            'Performance {{performance}} · Accessibility {{accessibility}} · Best practices {{practices}} · SEO {{seo}}',
            {
                performance:
                    result === 'failed' ? 35 : result === 'warning' ? 63 : 98,
                accessibility: 100,
                practices: 96,
                seo: 100,
            },
            'Device',
            'Mobile',
        ],
        cron: [
            result === 'warning'
                ? 'Ping late by {{duration}}'
                : 'Next run in {{duration}}',
            { duration: 240 },
            'Last ping',
            '',
        ],
        application_health: [
            result === 'warning'
                ? '{{count}} checks · {{warnings}} warning'
                : '{{count}} checks · {{warnings}} warnings',
            { count: 8, warnings: result === 'warning' ? 1 : 0 },
            'Last checked',
            '',
        ],
    };
    const [summary, summary_params, meta_label, meta] = defaults[key];
    if (result === 'pending')
        return {
            summary: 'Awaiting first result',
            summary_params: {},
            meta_label: 'Last checked',
            meta: '—',
        };
    if (result === 'errored-or-timed-out')
        return {
            summary: 'Check timed out after {{duration}}',
            summary_params: { duration: 10 },
            meta_label: 'Retries',
            meta: '2',
        };
    const failures: Partial<Record<CheckKey, string>> = {
        certificate_health: 'Certificate expired',
        domain: 'Domain expired',
        dns: 'Records changed',
        sitemap: 'Sitemap could not be fetched',
        mixed_content: 'Mixed content found',
        cron: 'scheduled_task.missed',
        application_health: 'Application health failed',
    };
    return {
        summary: result === 'failed' && failures[key] ? failures[key] : summary,
        summary_params,
        meta_label,
        meta,
    };
}
// Each check owns its configuration and measured quantity; none inherits HTTP fields.
const checkDetailFixtures: Record<
    CheckKey,
    {
        metric: string;
        unit: string;
        values: [number, number, number];
        interval: number;
    }
> = {
    uptime: {
        metric: 'Response time',
        unit: 'ms',
        values: [182, 2400, 3500],
        interval: 60,
    },
    performance: {
        metric: 'p95 response time',
        unit: 'ms',
        values: [640, 2400, 3500],
        interval: 60,
    },
    certificate_health: {
        metric: 'Days until expiry',
        unit: 'days',
        values: [71, 9, 0],
        interval: 86400,
    },
    domain: {
        metric: 'Days until expiry',
        unit: 'days',
        values: [164, 8, 0],
        interval: 86400,
    },
    dns: {
        metric: 'Changed records',
        unit: 'records',
        values: [0, 2, 4],
        interval: 60,
    },
    sitemap: {
        metric: 'Sitemap issues',
        unit: 'issues',
        values: [0, 3, 1],
        interval: 3600,
    },
    broken_links: {
        metric: 'Broken URLs',
        unit: 'URLs',
        values: [0, 3, 3],
        interval: 3600,
    },
    mixed_content: {
        metric: 'Insecure resources',
        unit: 'resources',
        values: [0, 3, 8],
        interval: 3600,
    },
    lighthouse: {
        metric: 'Performance score',
        unit: 'score',
        values: [98, 63, 35],
        interval: 3600,
    },
    cron: {
        metric: 'Ping delay',
        unit: 'seconds',
        values: [0, 240, 600],
        interval: 300,
    },
    application_health: {
        metric: 'Health warnings',
        unit: 'warnings',
        values: [0, 1, 3],
        interval: 60,
    },
};

function dashboardSites(state: DesignPageProps['state']): SiteDto[] {
    return state === 'empty' ? [] : sites;
}
export const dashboardEvents: EventDto[] = (
    [
        {
            run_id: null,
            id: 1,
            site_id: 23,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[22]!.name,
            client_name: clients.find(
                (client) => client.id === sites[22]!.client_id,
            )!.name,
            incident_id: 23,
            check: 'uptime',
            type: 'incident.opened',
            severity: 'down',
            time: incidentStartedAt,
            message: 'Unexpected HTTP status',
            message_params: {},
            changes: [],
            duration_seconds: 840,
        },
        {
            run_id: null,
            id: 2,
            site_id: 22,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[21]!.name,
            client_name: clients.find(
                (client) => client.id === sites[21]!.client_id,
            )!.name,
            incident_id: 22,
            check: 'cron',
            type: 'incident.opened',
            severity: 'error',
            time: new Date(snapshotNow - 2220000).toISOString(),
            message: 'Check timed out',
            message_params: {},
            changes: [],
            duration_seconds: 2220,
        },
        {
            run_id: null,
            id: 3,
            site_id: 21,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[20]!.name,
            client_name: clients.find(
                (client) => client.id === sites[20]!.client_id,
            )!.name,
            incident_id: null,
            check: 'certificate_health',
            type: 'check.result_changed',
            severity: 'warning',
            time: new Date(snapshotNow - 8160000).toISOString(),
            message: 'Certificate expires soon.',
            message_params: {},
            changes: [],
            duration_seconds: 8160,
        },
        {
            run_id: null,
            id: 4,
            site_id: 1,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[0]!.name,
            client_name: clients.find(
                (client) => client.id === sites[0]!.client_id,
            )!.name,
            incident_id: null,
            check: 'uptime',
            type: 'incident.resolved',
            severity: 'recovered',
            time: new Date(snapshotNow - 10800000).toISOString(),
            message: 'Site recovered',
            message_params: {},
            changes: [],
            duration_seconds: 192,
        },
        {
            run_id: null,
            id: 5,
            site_id: 3,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[2]!.name,
            client_name: clients.find(
                (client) => client.id === sites[2]!.client_id,
            )!.name,
            incident_id: null,
            check: 'uptime',
            type: 'incident.resolved',
            severity: 'recovered',
            time: new Date(snapshotNow - 18360000).toISOString(),
            message: 'Site recovered',
            message_params: {},
            changes: [],
            duration_seconds: 361,
        },
        {
            run_id: null,
            id: 6,
            site_id: 23,
            client_id: null,
            icon_url: null,
            icon_backing: 'none',
            site_name: sites[22]!.name,
            client_name: clients.find(
                (client) => client.id === sites[22]!.client_id,
            )!.name,
            incident_id: 21,
            check: 'uptime',
            type: 'incident.resolved',
            severity: 'recovered',
            time: new Date(snapshotNow - 86400000 + 192000).toISOString(),
            message: 'Site recovered',
            message_params: {},
            changes: [],
            duration_seconds: 192,
        },
    ] satisfies EventDto[]
).sort((left, right) => Date.parse(right.time) - Date.parse(left.time));

function checkHistoryFixture(site: SiteDto, check: CheckDto) {
    const detail = checkDetailFixtures[check.key];
    const history = Array.from(
        { length: check.checked_at === null ? 0 : 3 },
        (_, index) => {
            const checked_at = new Date(
                new Date(check.checked_at!).getTime() -
                    index * detail.interval * 1000,
            ).toISOString();
            const incident = dashboardEvents.find(
                (event) =>
                    event.site_id === site.id && event.check === check.key,
            );
            const result =
                index === 0 ||
                (check.open_incident_id !== null &&
                    incident &&
                    checked_at >= incident.time)
                    ? check.result
                    : detail.unit === 'days' &&
                        ['failed', 'warning'].includes(check.result)
                      ? 'warning'
                      : 'succeeded';
            const summary = checkSummary(check.key, result);
            const expiry =
                check.checked_at === null
                    ? null
                    : check.key === 'certificate_health'
                      ? certificateSnapshot(site, check).certificate_details!
                            .valid_until
                      : summary.summary_params.date;
            const days =
                typeof expiry === 'string'
                    ? Math.ceil(
                          (new Date(expiry).getTime() -
                              new Date(checked_at).getTime()) /
                              86400000,
                      )
                    : null;
            const value =
                result === 'errored-or-timed-out'
                    ? null
                    : detail.unit === 'days' && days !== null
                      ? Math.max(0, days)
                      : detail.values[
                            result === 'failed'
                                ? 2
                                : result === 'warning'
                                  ? 1
                                  : 0
                        ];
            return {
                ...check,
                result,
                ...summary,
                summary:
                    check.key === 'certificate_health' &&
                    days === 1 &&
                    result !== 'failed'
                        ? 'Valid · expires in {{days}} day'
                        : summary.summary,
                summary_params: {
                    ...summary.summary_params,
                    ...(check.key === 'certificate_health' && days !== null
                        ? { days }
                        : {}),
                },
                checked_at,
                value,
            };
        },
    );
    return { ...detail, history };
}

export function overviewEvents(site: SiteDto): EventDto[] {
    if (site.state === 'pending') return [];
    return (
        [
            ...dashboardEvents.filter((event) => event.site_id === site.id),
            {
                run_id: null,
                id: 101,
                site_id: site.id,
                client_id: null,
                icon_url: null,
                icon_backing: 'none',
                site_name: site.name,
                client_name: clients.find(
                    (client) => client.id === site.client_id,
                )!.name,
                type: 'certificate.renewed',
                severity: 'info',
                incident_id: null,
                message_params: {},
                changes: [],
                check: 'certificate_health',
                time: new Date(snapshotNow - 10800000).toISOString(),
                message: 'Certificate renewed',
                duration_seconds: null,
            },
            {
                run_id: null,
                id: 102,
                site_id: site.id,
                client_id: null,
                icon_url: null,
                icon_backing: 'none',
                site_name: site.name,
                client_name: clients.find(
                    (client) => client.id === site.client_id,
                )!.name,
                type: 'dns.verified',
                severity: 'info',
                incident_id: null,
                message_params: {},
                changes: [],
                check: 'dns',
                time: new Date(snapshotNow - 86400000).toISOString(),
                message: 'DNS records verified',
                duration_seconds: null,
            },
        ] satisfies EventDto[]
    ).sort((left, right) => Date.parse(right.time) - Date.parse(left.time));
}

export const incidentSnapshot: DesignIncidentDto[] = [
    ...sites.flatMap((site) =>
        site.checks
            .filter((check) => check.open_incident_id !== null)
            .map((check) => {
                const event = dashboardEvents.find(
                    (event) =>
                        event.site_id === site.id &&
                        event.check === check.key &&
                        event.type === 'incident.opened',
                );
                if (!event || check.open_incident_id === null)
                    throw new Error('Open incident requires a snapshot event');
                return {
                    id: check.open_incident_id,
                    site_id: site.id,
                    icon_url: null,
                    icon_backing: 'none' as const,
                    site_name: site.name,
                    client_id: site.client_id,
                    check: check.key,
                    snoozed_until: null,
                    cause:
                        check.result === 'errored-or-timed-out'
                            ? ('errored' as const)
                            : ('failed' as const),
                    duration_seconds: event.duration_seconds!,
                    started_at: event.time,
                    resolved_at: null,
                    error: event.message,
                    summary: null,
                    presentation: null,
                    summary_params: {},
                    uptime_parent_incident_id: null,
                    affected_checks: [],
                    uptime_recheck_after: null,
                    held_after_recovery: false,
                    dependency_headline_key: null,
                    next_run_at: null,
                };
            }),
    ),
    {
        id: 21,
        site_id: 23,
        icon_url: null,
        icon_backing: 'none',
        site_name: 'api.northwind.example',
        client_id: 2,
        check: 'uptime',
        snoozed_until: null,
        cause: 'failed',
        duration_seconds: 192,
        started_at: new Date(snapshotNow - 86400000).toISOString(),
        resolved_at: new Date(snapshotNow - 86400000 + 192000).toISOString(),
        error: 'Unexpected HTTP status',
        summary: null,
        presentation: null,
        summary_params: {},
        uptime_parent_incident_id: null,
        affected_checks: [],
        uptime_recheck_after: null,
        held_after_recovery: false,
        dependency_headline_key: null,
        next_run_at: null,
    },
];

function incidentFixture(
    id: number,
    _state: DesignPageProps['state'],
): DesignIncidentDto {
    const incident = incidentSnapshot.find((incident) => incident.id === id);
    if (!incident) throw new Error(`Unknown incident fixture ${id}`);
    return incident;
}

export function siteListFixtures(
    state: DesignPageProps['state'],
    count = 25,
): SiteDto[] {
    const sites = dashboardSites(state);
    return Array.from({ length: Math.ceil(count / 25) }, (_, region) =>
        sites.map((site) => {
            const id = site.id + region * 25;
            const name = region
                ? site.name.replace('.example', `.eu-${region + 1}.example`)
                : site.name;
            const url = region
                ? site.url.replace('.example', `.eu-${region + 1}.example`)
                : site.url;
            return {
                ...site,
                id,
                name,
                url,
                identity: { ...site.identity, id, name, url },
            };
        }),
    )
        .flat()
        .slice(0, count);
}

// Design preview only. Installed recommended checks come from CreateSite in production.
const recommendedChecks: CheckKey[] = [
    'uptime',
    'performance',
    'certificate_health',
    'domain',
    'dns',
    'broken_links',
    'mixed_content',
    'sitemap',
    'lighthouse',
];
const httpsChecks: CheckKey[] = ['certificate_health', 'mixed_content'];
function certificateSnapshot(
    site: SiteDto,
    check: CheckDto,
): Heptapod.Addons.CertificateHealth.CertificateSnapshot {
    if (check.checked_at === null)
        throw new Error('Certificate snapshot requires a measured check');
    const days =
        check.result === 'failed'
            ? 0
            : check.summary_params.days === undefined
              ? 71
              : Number(check.summary_params.days);
    return {
        check_type: 'certificate_health',
        certificate_details: {
            fingerprint: 'fixture-fingerprint',
            issuer: "Let's Encrypt R11",
            domain: new URL(site.url).hostname,
            additional_domains: [],
            valid_from: new Date(
                Date.parse(check.checked_at) - 19 * 86400000,
            ).toISOString(),
            valid_until: new Date(
                Date.parse(check.checked_at) + days * 86400000,
            ).toISOString(),
            signature_algorithm: 'sha256WithRSAEncryption',
            is_valid: check.result !== 'failed',
        },
        certificate_checks: [],
        certificate_chain_issuers: ["Let's Encrypt R11"],
    };
}
const pagination = (total: number): Heptapod.Data.Ui.PaginationData => ({
    current_page: 1,
    per_page: 50,
    total,
    last_page: Math.max(1, Math.ceil(total / 50)),
    next_page_url: null,
    previous_page_url: null,
});
export function sitesPageFixture(
    state: DesignPageProps['state'],
    count = 25,
): Heptapod.Data.Ui.SitesIndexPageData {
    const data = siteListFixtures(state, count);
    const attention = data.filter((site) => isAttentionState(site.state));
    const stateCount = (siteState: Heptapod.Enums.SiteStatus) =>
        data.filter((site) => site.state === siteState).length;
    return {
        sites: data,
        clients,
        tags: [],
        summary: {
            total: data.length,
            attention: attention.length,
            paused: data.filter((site) => site.state === 'paused').length,
        },
        can_create: true,
        pagination: pagination(data.length),
        filters: {
            search: null,
            client_id: null,
            state: null,
            tag: null,
            check: null,
            attention: false,
            sort: 'status',
        },
        tiles: {
            sites: data.length,
            ok: stateCount('ok'),
            down: stateCount('down'),
            check_errors: stateCount('check-error'),
            warning: stateCount('warning'),
            pending: stateCount('pending'),
            paused: stateCount('paused'),
            open_incidents: 0,
            uptime: null,
            certificates_expiring: 0,
            cron_jobs_failing: 0,
            failed: stateCount('failed'),
            maintenance: stateCount('maintenance'),
            disabled: stateCount('disabled'),
            unavailable: stateCount('unavailable'),
            certificates_expiring_30_days: null,
            domains_expiring_30_days: null,
            scheduled_tasks: null,
            scheduled_tasks_overdue_pings: null,
        },
        needs_attention: attention.slice(0, 5),
        needs_attention_total: attention.length,
    };
}
function schemaField(
    type: Heptapod.Contracts.Schema.FieldType,
    rules: string[] = [],
    labelKey: string | null = null,
    items: Heptapod.Contracts.Schema.Field | null = null,
    properties: Record<string, Heptapod.Contracts.Schema.Field> = {},
): Heptapod.Contracts.Schema.Field {
    return {
        type,
        rules,
        properties,
        items,
        presentation:
            labelKey === null ? null : { labelKey, helpKey: null, options: {} },
        additionalProperties: false,
        targetUrl: false,
        uppercase: false,
        trimmedStrings: false,
    };
}
// Design fixture: production uses server-provided uptime schema.
// Design fixture: production uses server-provided performance, certificate, domain, DNS and application-health schemas.
const checkSchemas: Record<CheckKey, Heptapod.Contracts.Schema.Schema> = {
    uptime: {
        fields: {
            http_verb: schemaField('string', [
                'sometimes',
                'in:GET,HEAD,POST,PUT,PATCH,DELETE,OPTIONS',
            ]),
            timeout: schemaField('integer', ['sometimes', 'min:1']),
            look_for_string: schemaField('string', ['sometimes', 'nullable']),
            http_client_headers: schemaField(
                'list',
                ['sometimes'],
                null,
                schemaField('object', [], null, null, {
                    name: schemaField('string', ['required']),
                    value: schemaField('secret', ['present']),
                }),
            ),
        },
    },
    performance: {
        fields: {
            threshold_in_ms: schemaField('integer', ['sometimes', 'min:0']),
            change_percentage: schemaField('integer', ['sometimes', 'min:0']),
        },
    },
    certificate_health: {
        fields: {
            expires_soon_threshold_in_days: schemaField(
                'integer',
                ['sometimes', 'min:0'],
                'certificate_health.threshold',
            ),
        },
    },
    domain: {
        fields: {
            expires_soon_threshold_in_days: schemaField(
                'integer',
                ['sometimes', 'min:0'],
                'domain.threshold',
            ),
        },
    },
    dns: {
        fields: {
            ignored_record_types: schemaField(
                'list',
                ['sometimes', 'max:7'],
                'dns.ignored_record_types',
                schemaField('string', [
                    'required',
                    'distinct',
                    'in:A,AAAA,CNAME,MX,TXT,NS,CAA',
                ]),
            ),
            check_nameservers_in_sync: schemaField(
                'boolean',
                ['sometimes'],
                'dns.check_nameservers_in_sync',
            ),
        },
    },
    application_health: {
        fields: {
            result_url: schemaField(
                'string',
                ['required', 'url:http,https', 'max:2048'],
                'application_health.result_url',
            ),
            secret: schemaField(
                'secret',
                ['required', 'max:10000'],
                'application_health.secret',
            ),
        },
    },
    // Cron tasks have their own definition API, not parent-check config fields.
    cron: { fields: {} },
    // TODO: schema after addon merge; current presentation remains a preview only.
    broken_links: { fields: {} },
    mixed_content: { fields: {} },
    sitemap: { fields: {} },
    lighthouse: { fields: {} },
};
export const addSitePage: Heptapod.Data.Ui.AddSitePageData = {
    can_create_site: false,
    timezones: ['UTC', 'Europe/Berlin'],
    clients,
    selected_client_id: null,
    checks: (Object.keys(checkTypes) as CheckKey[]).map((key) => ({
        key,
        label_key: checkTypes[key].label,
        icon: checkIconIds[key],
        recommended: recommendedChecks.includes(key),
        requires_https: httpsChecks.includes(key),
        interval_seconds:
            key === 'uptime' || key === 'performance' ? 60 : 86400,
        config_schema: checkSchemas[key],
        task_definition_schema: null,
        unavailable_reason: null,
    })),
};
export function incidentPageFixture(
    id: number,
    state: DesignPageProps['state'],
): Heptapod.Data.Ui.IncidentDetailPageData {
    const incident = incidentFixture(id, state);
    const site = sites.find((site) => site.id === incident.site_id);
    if (!site) throw new Error('Incident requires a snapshot site');
    return {
        site,
        header: site.identity,
        incident,
        client: clients.find((client) => client.id === site.client_id)!,
        runs: checkTabPageFixture(
            site,
            site.checks.find((check) => check.key === incident.check)!,
        ).runs,
        internal_note: null,
        note_author: null,
        note_updated_at: null,
        other_deliveries: [],
        evidence: null,
        opening_presentation: null,
        opening_delivery_explanation: null,
        current_presentation: null,
        current_evidence: null,
        current_findings: null,
        timeline: [],
        related_incidents: [],
        expanded_notifications: null,
        can_run: false,
        can_snooze: false,
        can_unsnooze: false,
        alert_preview_available: false,
        maintenance: null,
        opened_during_maintenance: false,
        mail_design: 'branded',
    };
}
function responseTimeSeries(
    site: SiteDto,
    history = overviewHistory(site),
): Heptapod.Data.Ui.ResponseTimeSeriesData[] {
    const measured = site.state !== 'pending' && site.state !== 'paused';
    const lastMinute = Math.floor(snapshotNow / 60000) * 60000;
    const minutes: Heptapod.Data.Ui.ResponseTimeBucketData[] = Array.from(
        { length: 1440 },
        (_, minute) => ({
            time: new Date(lastMinute - (1439 - minute) * 60000).toISOString(),
            average_ms: measured
                ? probeMetrics[site.id]![minute]!.total_time_in_seconds! * 1000
                : null,
            p95_ms: null,
            maximum_ms: null,
            failure_count: 0,
            error_count: 0,
            warning_count: 0,
        }),
    );
    const daily: Heptapod.Data.Ui.ResponseTimeBucketData[] = history.map(
        (bucket, day) => ({
            time: bucket.date,
            average_ms:
                bucket.uptime === null
                    ? null
                    : 182 +
                      Math.round(
                          Math.sin(day * 1.7) * 24 + Math.cos(day * 0.4) * 18,
                      ) +
                      (bucket.status === 'failed' ? 800 : 0),
            p95_ms: null,
            maximum_ms: null,
            failure_count: 0,
            error_count: 0,
            warning_count: 0,
        }),
    );
    // Fixture minute buckets contain one equal-weight observation per site.
    const recent = minutes
        .slice(-15)
        .flatMap((point) =>
            point.average_ms === null ? [] : [point.average_ms],
        );
    const recent_average_ms = recent.length
        ? recent.reduce((sum, value) => sum + value, 0) / recent.length
        : null;
    return [
        { key: '24h', points: minutes, recent_average_ms },
        { key: '7d', points: daily.slice(-7), recent_average_ms },
        { key: '30d', points: daily.slice(-30), recent_average_ms },
        { key: '90d', points: daily, recent_average_ms },
    ];
}
function checkSettingsFixture(
    site: SiteDto,
): Record<CheckKey, Record<string, unknown>> {
    return {
        uptime: {
            http_verb: 'GET',
            timeout: 10,
            look_for_string: null,
            http_client_headers: [],
        },
        performance: { threshold_in_ms: 2000, change_percentage: 20 },
        certificate_health: { expires_soon_threshold_in_days: 14 },
        domain: { expires_soon_threshold_in_days: 14 },
        dns: { ignored_record_types: [], check_nameservers_in_sync: true },
        application_health: {
            result_url: new URL('/health', site.url).href,
            secret: null,
        },
        cron: {},
        sitemap: {},
        broken_links: {},
        mixed_content: {},
        lighthouse: {},
    };
}

export function checkTabPageFixture(
    site: SiteDto,
    check: CheckDto,
): Heptapod.Data.Ui.CheckTabPageData {
    const presentation = checkHistoryFixture(site, check);
    const detail: Heptapod.Contracts.CheckDetailData | null =
        check.checked_at === null
            ? null
            : check.key === 'certificate_health'
              ? {
                    ...certificateSnapshot(site, check),
                    check_type: 'certificate_health',
                }
              : check.key === 'domain'
                ? {
                      check_type: 'domain',
                      domain: new URL(site.url).hostname
                          .split('.')
                          .slice(-2)
                          .join('.'),
                      registrar: 'INWX',
                      expires_at: '2027-03-14',
                      registered_at: '2020-03-14',
                      last_changed_at: null,
                      last_updated_in_rdap_db_at: null,
                      created_at: check.checked_at!,
                      nameservers: ['ns1.northwind.example'],
                      domain_statuses: {},
                      rdap_domain_response: null,
                      date_precision: 'day',
                      source: 'rdap',
                      server: 'rdap.example',
                      not_supported_reason: null,
                  }
                : check.key === 'dns'
                  ? {
                        check_type: 'dns',
                        dns_records: [],
                        raw_dns_records: {},
                        normalized_records: {},
                        issues: [],
                        authoritative_nameservers: ['ns1.northwind.example'],
                        diagnostics: [],
                    }
                  : null;
    return {
        site,
        header: site.identity,
        check,
        can_run: true,
        can_move: true,
        run_all_checks: [],
        maintenance: null,
        performance_noise: null,
        performance_unavailable: null,
        freshness_suppressed_by: null,
        freshness_suppressed_since: null,
        freshness_latest_error_at: null,
        freshness_suppression: null,
        mail_design: 'branded',
        failure_threshold: 2,
        schedule: {
            freshness: {
                state: 'fresh',
                awaiting_result_since: null,
                not_fresh_since: null,
                threshold_seconds: 900,
                lost_attempts: 0,
            },
            last_loss: null,
            interval_seconds: 60,
            next_due_at: check.checked_at,
            last_started_at: check.checked_at,
            last_finished_at: check.checked_at,
            last_duration_ms: null,
            run: {
                check_id: check.id,
                phase: 'scheduled',
                attempt_id: null,
                queue: null,
                queue_position: null,
                progress_n: null,
                progress_total: null,
                phase_label: null,
                started_at: null,
                actor: null,
                due_at: check.checked_at,
                can_cancel: false,
                requested_at: null,
            },
        },
        runs: presentation.history.map((row, index) => ({
            id: check.id * 10 + index,
            result: row.result,
            started_at: row.checked_at!,
            ended_at: row.checked_at!,
            summary: row.summary,
            summary_params: row.summary_params,
            presentation: row.presentation,
        })),
        metrics: [
            {
                key: check.key,
                unit: presentation.unit,
                points: presentation.history.toReversed().map((row) => ({
                    time: row.checked_at!,
                    value: row.value,
                    failure_count: 0,
                    error_count: 0,
                    warning_count: 0,
                    failure_status: null,
                })),
            },
        ],
        check_settings: {
            site_id: site.id,
            check_id: check.id,
            check_key: check.key,
            label_key: check.label_key,
            enabled: check.enabled,
            interval_seconds: 60,
            config_schema: checkSchemas[check.key],
            settings: checkSettingsFixture(site)[check.key],
            can_update: true,
        },
        detail,
        response_times: responseTimeSeries(site),
        phase_series: [],
        response_p95_hourly: null,
        history: null,
        client: clients.find((client) => client.id === site.client_id)!,
    };
}
