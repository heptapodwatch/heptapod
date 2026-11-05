import { CardToolbarActions } from '@/components/card-toolbar-actions';
import { ListFooter } from '@/components/list-footer';
import { TooltipHint } from '@/components/truncated-text';
import { cn } from '@/lib/utils';
import { SectionHeading } from '@/components/section-heading';
import { useTranslation } from 'react-i18next';
import { useState, type ReactNode } from 'react';
import { MonitoringTime } from '@/components/monitoring';
import { Alert, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardAction,
} from '@/components/ui/card';
import {
    listCardPadding,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { usePage } from '@inertiajs/react';
import { findingsTimestamp } from '@/lib/findings-export';
import { safeHttpUrl, TextLink } from '@/components/text-link';
import {
    FindingsExport,
    FindingsExportFallback,
    type FindingsExportContext,
} from '@/components/findings-export';

type Findings = Heptapod.Addons.Crawl.CrawlFindingsData;

type FindingRow = {
    url: string;
    status: ReactNode;
    source?: string | null;
    problem?: ReactNode;
    linkText?: string | null;
    since?: ReactNode;
    marker?: ReactNode;
};

/**
 * Same-origin URLs (scheme, host and port equal) read as path, query and hash; other URLs stay absolute.
 * Invalid URLs are valid sitemap findings; leave their recorded value literal.
 */
function findingUrl(value: string, origin?: string) {
    if (!origin || !URL.canParse(value)) return value;
    const url = new URL(value);
    return url.origin === new URL(origin).origin
        ? `${url.pathname}${url.search}${url.hash}`
        : value;
}

/**
 * At most two lines of the recorded URL (unless clamp is off). Only recorded HTTP(S) URLs may become links (href and title stay absolute);
 * remote text is never markup and other values stay literal.
 */
function FindingLink({
    value,
    origin,
    linkText,
    enabled,
    clamp = true,
}: {
    value: string;
    origin?: string;
    linkText?: string | null;
    enabled: boolean;
    /** Off where the URL continues a sentence ("Found on …") and must stay inline. */
    clamp?: boolean;
}) {
    const text = findingUrl(value, origin);
    const url = enabled ? safeHttpUrl(value) : null;
    const fragment = Array.from(linkText?.trim() ?? '')
        .slice(0, 200)
        .join('')
        .trim();
    if (url && fragment) {
        // A recorded anchor must not prevent text-fragment navigation.
        url.hash = `:~:text=${encodeURIComponent(fragment).replaceAll('-', '%2D')}`;
    }
    return (
        <span
            className={
                clamp ? 'line-clamp-2 [overflow-wrap:anywhere]' : undefined
            }
        >
            {url ? (
                <TextLink external url href={url.href} title={value}>
                    {text}
                </TextLink>
            ) : (
                <TooltipHint content={text === value ? undefined : value}>
                    <span>{text}</span>
                </TooltipHint>
            )}
        </span>
    );
}

/** Crawl and sitemap evidence share desktop columns and narrow-container cards. */
export function FindingsTable({
    rows,
    showSource = true,
    showProblem = true,
    problemLabel = 'Kind',
    stackWhenNarrow = true,
    showFindingDetails = false,
    linkUrls = true,
    muted = false,
    origin,
    empty,
    total,
}: {
    rows: FindingRow[];
    showSource?: boolean;
    showProblem?: boolean;
    problemLabel?: string;
    stackWhenNarrow?: boolean;
    showFindingDetails?: boolean;
    /** Link http(s) URLs in the URL and found-on columns; independent of showFindingDetails. */
    linkUrls?: boolean;
    muted?: boolean;
    /** The site origin; same-origin URLs show as path. */
    origin?: string;
    /** Shown instead of the table when there are no rows. */
    empty?: string;
    /** Retained row count when rows are a bounded slice; adds the "showing N of M" footer. */
    total?: number;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    if (rows.length === 0) {
        return empty ? <p className="text-muted-foreground">{empty}</p> : null;
    }
    return (
        <div className="space-y-2">
            <div className="@container/findings overflow-hidden rounded-lg border">
                {stackWhenNarrow && (
                    <ul className="divide-y @[640px]/findings:hidden">
                        {rows.map((row, index) => (
                            <li
                                key={`${row.url}-${index}`}
                                className={cn('space-y-2', listCardPadding)}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span
                                        className={
                                            muted
                                                ? 'font-mono text-xs text-muted-foreground tabular-nums'
                                                : 'font-mono text-xs text-[var(--status-down)] tabular-nums'
                                        }
                                    >
                                        {row.status}
                                    </span>
                                    {row.marker}
                                    {showFindingDetails && row.since && (
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            {t('Since')} {row.since}
                                        </span>
                                    )}
                                </div>
                                <p className="font-mono text-xs">
                                    <FindingLink
                                        value={row.url}
                                        origin={origin}
                                        enabled={linkUrls}
                                    />
                                </p>
                                {showProblem && row.problem && (
                                    <div className="text-muted-foreground">
                                        {row.problem}
                                    </div>
                                )}
                                {showSource && (
                                    <div className="space-y-1 text-xs text-muted-foreground">
                                        <p>
                                            {t('Found on')}{' '}
                                            <span className="font-mono">
                                                {row.source ? (
                                                    <FindingLink
                                                        value={row.source}
                                                        origin={origin}
                                                        linkText={row.linkText}
                                                        enabled={linkUrls}
                                                        clamp={false}
                                                    />
                                                ) : (
                                                    t('No source page recorded')
                                                )}
                                            </span>
                                        </p>
                                        {showFindingDetails && (
                                            <p className="[overflow-wrap:anywhere]">
                                                {row.linkText
                                                    ? `“${row.linkText}”`
                                                    : t(
                                                          'No link text recorded',
                                                      )}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <div
                    className={
                        stackWhenNarrow
                            ? 'hidden @[640px]/findings:block'
                            : undefined
                    }
                >
                    <Table className="table-fixed">
                        <TableHeader>
                            <TableRow>
                                <TableHead
                                    className={
                                        showSource ? 'w-[35%]' : 'w-[42%]'
                                    }
                                >
                                    {t(
                                        showFindingDetails
                                            ? 'Broken URL'
                                            : 'URL',
                                    )}
                                </TableHead>
                                <TableHead className="w-28">
                                    {t(
                                        showFindingDetails
                                            ? 'Result'
                                            : 'Status',
                                    )}
                                </TableHead>
                                {showSource && (
                                    <TableHead>{t('Found on')}</TableHead>
                                )}
                                {showFindingDetails ? (
                                    <>
                                        <TableHead>{t('Link text')}</TableHead>
                                        <TableHead className="w-24">
                                            {t('Since')}
                                        </TableHead>
                                    </>
                                ) : (
                                    showProblem && (
                                        <TableHead>{t(problemLabel)}</TableHead>
                                    )
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => (
                                <TableRow key={`${row.url}-${index}`}>
                                    <TableCell className="font-mono text-xs whitespace-normal">
                                        <FindingLink
                                            value={row.url}
                                            origin={origin}
                                            enabled={linkUrls}
                                        />
                                        {row.marker}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            muted
                                                ? 'font-mono text-xs whitespace-normal text-muted-foreground tabular-nums'
                                                : 'font-mono text-xs whitespace-normal text-[var(--status-down)] tabular-nums'
                                        }
                                    >
                                        {row.status}
                                    </TableCell>
                                    {showSource && (
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {row.source ? (
                                                <FindingLink
                                                    value={row.source}
                                                    origin={origin}
                                                    linkText={row.linkText}
                                                    enabled={linkUrls}
                                                />
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                    )}
                                    {showFindingDetails ? (
                                        <>
                                            <TableCell className="text-xs [overflow-wrap:anywhere] whitespace-normal">
                                                {row.linkText ??
                                                    t('No link text recorded')}
                                            </TableCell>
                                            <TableCell className="text-xs whitespace-normal text-muted-foreground">
                                                {row.since}
                                            </TableCell>
                                        </>
                                    ) : (
                                        showProblem && (
                                            <TableCell className="text-xs whitespace-normal">
                                                {row.problem}
                                            </TableCell>
                                        )
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
            {total !== undefined && total > rows.length && (
                <ListFooter
                    count={t('findings.showing', {
                        shown: format.number(rows.length),
                        total: format.number(total),
                    })}
                />
            )}
        </div>
    );
}

export function NotVerifiableFindings({
    rows,
    origin,
    checkType,
}: {
    rows: {
        url: string;
        reason: string;
        status: number | null;
        source?: string | null;
    }[];
    /** The site origin; same-origin URLs show as path. */
    origin?: string;
    checkType: 'broken_links' | 'mixed_content' | 'sitemap';
}) {
    const { t } = useTranslation();
    const format = useFormat();
    if (rows.length === 0) return null;
    const translationPrefix = checkType === 'sitemap' ? 'sitemap' : 'crawl';
    return (
        <section
            className="space-y-2 text-muted-foreground"
            aria-label={t(`${translationPrefix}.not_verifiable.group`)}
        >
            <SectionHeading level={3}>
                {t(`${translationPrefix}.not_verifiable.group`)} ·{' '}
                {format.number(rows.length)}
            </SectionHeading>
            <p className="text-xs">
                {t(
                    checkType === 'mixed_content'
                        ? 'crawl.not_verifiable.mixed_explanation'
                        : `${translationPrefix}.not_verifiable.explanation`,
                )}
            </p>
            <FindingsTable
                muted
                origin={origin}
                showSource={translationPrefix === 'crawl'}
                problemLabel="Result"
                rows={rows.map((finding) => ({
                    url: finding.url,
                    status: finding.status,
                    source: finding.source,
                    problem: t(
                        `${translationPrefix}.not_verifiable.${finding.reason}`,
                    ),
                }))}
            />
        </section>
    );
}

export function CrawlFindings({
    history,
    latest,
    newSince,
    action,
    origin,
    exportContext,
}: {
    history: Findings;
    latest: Heptapod.Addons.Crawl.CrawlSnapshot | null;
    newSince?: string | null;
    action?: ReactNode;
    /** The site origin; same-origin URLs show as path. */
    origin?: string;
    exportContext?: FindingsExportContext;
}) {
    const { t } = useTranslation();
    const { timezone } = usePage().props;
    const format = useFormat();
    const [manualText, setManualText] = useState<string | null>(null);
    const timestamp = (value: string) => findingsTimestamp(value, timezone);
    const isNew = (finding: Findings['findings'][number]) =>
        Boolean(
            newSince &&
            finding.after_first_complete_crawl &&
            finding.first_seen_in_retained_history_at &&
            Date.parse(finding.first_seen_in_retained_history_at) >
                Date.parse(newSince),
        );
    const notVerifiable = history.not_verifiable;
    const findings = history.findings;
    const notCrawlable = latest?.notes.some((note) =>
        note.key.startsWith('crawl.not_crawlable.'),
    );
    const incomplete = latest
        ? [
              latest.page_cap_reached &&
                  `${t('Page limit reached')} (${format.number(latest.page_cap)})`,
              latest.time_limit_reached && t('Time limit reached'),
              latest.resource_cap_reached && t('Resource limit reached'),
              latest.comparison_limit_reached && t('Comparison limit reached'),
              ...(!notCrawlable && !latest.discovery_complete
                  ? latest.notes
                        .filter((note) =>
                            note.key.startsWith('crawl.discovery.'),
                        )
                        .map((note) => format.presentationPart(note))
                  : []),
          ].filter(Boolean)
        : [];

    return (
        <Card className="overflow-hidden">
            <CardHeader>
                <CardTitle>
                    {t(
                        history.check_type === 'broken_links'
                            ? 'What we see now'
                            : 'Crawl findings',
                    )}
                </CardTitle>
                <CardAction>
                    <CardToolbarActions
                        outside={
                            <FindingsExportFallback
                                manualText={manualText}
                                onClose={() => setManualText(null)}
                            />
                        }
                    >
                        {exportContext &&
                            history.snapshot_run &&
                            history.observed_at && (
                                <FindingsExport
                                    onClipboardUnavailable={setManualText}
                                    context={exportContext}
                                    observedRunId={history.snapshot_run.id}
                                    observedAt={
                                        history.observed_at
                                            ? timestamp(history.observed_at)
                                            : null
                                    }
                                    downloadUrl={exportContext.downloadUrl(
                                        history.snapshot_run.id,
                                        history.snapshot_run.endedAt,
                                    )}
                                    totalRows={
                                        history.total_findings +
                                        history.total_not_verifiable
                                    }
                                    totalNotVerifiable={
                                        history.total_not_verifiable
                                    }
                                    groups={[
                                        {
                                            rows: history.findings.map(
                                                (finding) => ({
                                                    url: finding.url,
                                                    result:
                                                        history.check_type ===
                                                        'mixed_content'
                                                            ? t(
                                                                  'checks.mixed_content.label',
                                                              )
                                                            : String(
                                                                  finding.status_code ??
                                                                      (finding.reason_key
                                                                          ? t(
                                                                                finding.reason_key,
                                                                            )
                                                                          : t(
                                                                                'No response',
                                                                            )),
                                                              ),
                                                    foundOn: finding.found_on,
                                                    linkText: finding.link_text,
                                                    since: finding.first_seen_in_retained_history_at
                                                        ? timestamp(
                                                              finding.first_seen_in_retained_history_at,
                                                          )
                                                        : null,
                                                    isNew: isNew(finding),
                                                }),
                                            ),
                                        },
                                        {
                                            notVerifiable: true,
                                            rows: notVerifiable.map(
                                                (finding) => ({
                                                    url: finding.url,
                                                    result: t(
                                                        `crawl.not_verifiable.${finding.reason}`,
                                                    ),
                                                    foundOn: finding.source,
                                                }),
                                            ),
                                        },
                                    ]}
                                />
                            )}
                        {action}
                    </CardToolbarActions>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                {latest?.notes
                    .filter((note) => note.key.startsWith('crawl.coverage.'))
                    .map((note) => (
                        <p key={note.key} className="text-muted-foreground">
                            {format.presentationPart(note)}
                        </p>
                    ))}
                {latest &&
                    !latest.discovery_complete &&
                    !notCrawlable &&
                    incomplete.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('crawl.coverage.reason_not_recorded')}
                        </p>
                    )}
                {incomplete.length > 0 && latest && (
                    <Alert tone="info">
                        <AlertTitle>
                            {t('Latest run incomplete')} (
                            {incomplete.join(' · ')}) ·{' '}
                            <MonitoringTime value={latest.run_ended_at} />
                        </AlertTitle>
                    </Alert>
                )}
                <p className="text-muted-foreground">
                    {history.observed_at ? (
                        <>
                            {t('Findings from')}{' '}
                            <MonitoringTime value={history.observed_at} />
                        </>
                    ) : (
                        t('Awaiting first accepted crawl')
                    )}
                </p>
                {(history.page_cap_reached ||
                    history.time_limit_reached ||
                    history.resource_cap_reached) && (
                    <p role="status" className="text-muted-foreground">
                        {t('Accepted crawl incomplete')}
                        {history.page_cap_reached &&
                            ` · ${t('Page limit reached')} (${format.number(history.page_cap)})`}
                        {history.time_limit_reached &&
                            ` · ${t('Time limit reached')}`}
                        {history.resource_cap_reached &&
                            ` · ${t('Resource limit reached')}`}
                    </p>
                )}
                {history.weekly_change && (
                    <p className="text-muted-foreground">
                        {t('Since previous crawl')}:{' '}
                        {format.number(history.weekly_change.previous)} →{' '}
                        {format.number(history.weekly_change.current)}
                    </p>
                )}
                {(findings.length > 0 || history.observed_at) && (
                    <FindingsTable
                        stackWhenNarrow
                        origin={origin}
                        showFindingDetails={
                            history.check_type === 'broken_links'
                        }
                        rows={findings.map((finding) => ({
                            url: finding.url,
                            status: [
                                finding.status_code ??
                                    (finding.reason_key
                                        ? t(finding.reason_key)
                                        : t('No response')),
                                finding.current_reason_key
                                    ? t('crawl.finding.current_observation', {
                                          reason: t(finding.current_reason_key),
                                      })
                                    : null,
                            ]
                                .filter((part) => part !== null)
                                .join(' · '),
                            source: finding.found_on,
                            linkText: finding.link_text,
                            since: finding.first_seen_in_retained_history_at ? (
                                <MonitoringTime
                                    value={
                                        finding.first_seen_in_retained_history_at
                                    }
                                />
                            ) : (
                                t('Not retained')
                            ),
                            marker: isNew(finding) ? (
                                <TooltipHint
                                    content={t(
                                        'First seen in retained history',
                                    )}
                                >
                                    <span className="rounded border px-1.5 py-0.5 font-sans text-micro">
                                        {t('New')}
                                    </span>
                                </TooltipHint>
                            ) : null,
                            problem: (
                                <>
                                    {finding.internal
                                        ? t('Internal')
                                        : t('External')}
                                    {` · ${t(finding.kind_key)}`}
                                    {finding.reason_key &&
                                        finding.reason !== 'request_failed' && (
                                            <span className="block text-muted-foreground">
                                                {t(finding.reason_key)}
                                            </span>
                                        )}
                                    {finding.first_seen_in_retained_history_at && (
                                        <span className="block text-muted-foreground">
                                            {t(
                                                'First seen in retained history',
                                            )}{' '}
                                            ·{' '}
                                            <MonitoringTime
                                                value={
                                                    finding.first_seen_in_retained_history_at
                                                }
                                            />
                                        </span>
                                    )}
                                </>
                            ),
                        }))}
                        empty={t(
                            history.check_type === 'broken_links'
                                ? 'crawl.no_broken_findings'
                                : 'No findings in accepted crawl',
                        )}
                    />
                )}
                <NotVerifiableFindings
                    origin={origin}
                    checkType={
                        history.check_type === 'mixed_content'
                            ? 'mixed_content'
                            : 'broken_links'
                    }
                    rows={notVerifiable}
                />
            </CardContent>
        </Card>
    );
}
