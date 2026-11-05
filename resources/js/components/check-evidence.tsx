import { CardToolbarActions } from '@/components/card-toolbar-actions';
import { cn } from '@/lib/utils';
import { useState, type ReactNode } from 'react';
import { EvidenceFacts } from '@/components/evidence-facts';
import { ScoreRing } from '@/components/foundation-primitives';
import { useTranslation } from 'react-i18next';
import {
    CrawlFindings,
    FindingsTable,
    NotVerifiableFindings,
} from '@/components/crawl-findings';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { parseResult, runState } from '@/lib/status';
import {
    FindingsExport,
    FindingsExportFallback,
    type FindingsExportContext,
} from '@/components/findings-export';

function uptimeResultState(result: string) {
    return runState('uptime', parseResult(result));
}

export function LighthouseScores({
    metrics,
    evidence,
}: {
    metrics: Heptapod.Addons.Lighthouse.LighthouseMetrics | null;
    evidence: Heptapod.Addons.Lighthouse.LighthouseEvidence | null;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <>
            {metrics && (
                <div
                    role="group"
                    aria-label={t('lighthouse.scores')}
                    className="grid grid-cols-2 gap-4 sm:grid-cols-4"
                >
                    {(
                        [
                            'performance',
                            'accessibility',
                            'best_practices',
                            'seo',
                        ] as const
                    ).map((category) => (
                        <div
                            key={category}
                            className="grid justify-items-center gap-2"
                        >
                            <ScoreRing
                                score={metrics[`${category}_score`]}
                                label={t(`lighthouse.${category}`)}
                            />
                            <span className="text-xs text-muted-foreground">
                                {t(`lighthouse.${category}`)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
            {evidence && (
                <ul className="space-y-2 text-sm">
                    {evidence.issues.map((issue) => (
                        <li key={issue.category}>
                            {t('lighthouse.score_below', {
                                category: t(`lighthouse.${issue.category}`),
                                score: format.number(issue.actualScore),
                                threshold: format.number(
                                    issue.notificationThreshold,
                                ),
                            })}
                        </li>
                    ))}
                    {evidence.score_drops.map((drop) => (
                        <li key={drop.category}>
                            {t('lighthouse.score_drop', {
                                category: t(`lighthouse.${drop.category}`),
                                previous: format.number(drop.previousScore),
                                score: format.number(drop.actualScore),
                            })}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

export function CheckEvidence({
    type,
    detail,
    history,
    presentation,
    latest,
    action,
    since,
    requestedHost,
    origin,
    exportContext,
}: {
    type: Heptapod.Contracts.CheckKey;
    detail: Heptapod.Contracts.CheckDetailData | null;
    history: Heptapod.Contracts.CheckHistoryData | null;
    presentation?: Heptapod.Contracts.CheckRunPresentationData | null;
    latest?: Heptapod.Data.Ui.CheckRunData;
    action?: ReactNode;
    since?: string | null;
    requestedHost?: string;
    /** The site origin; findings tables show same-origin URLs as path. */
    origin?: string;
    exportContext?: FindingsExportContext;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [manualText, setManualText] = useState<string | null>(null);
    const cardHeading = (title: string) => (
        <CardHeader>
            <CardTitle>{t(title)}</CardTitle>
            <CardAction>{action}</CardAction>
        </CardHeader>
    );
    if (
        (type === 'broken_links' || type === 'mixed_content') &&
        (history?.check_type === 'broken_links' ||
            history?.check_type === 'mixed_content')
    ) {
        return (
            <CrawlFindings
                history={history}
                latest={
                    detail?.check_type === 'broken_links' ||
                    detail?.check_type === 'mixed_content'
                        ? detail
                        : null
                }
                newSince={type === 'broken_links' ? since : null}
                action={action}
                origin={origin}
                exportContext={exportContext}
            />
        );
    }
    if (detail?.check_type === 'sitemap') {
        const problemText = (
            issue: Heptapod.Addons.Sitemap.SitemapSnapshot['issues'][number],
        ) =>
            issue.xml_reason
                ? format.summary(`sitemap.xml.${issue.xml_reason}`, {
                      xml_line: issue.xml_line ?? null,
                      xml_element: issue.xml_element ?? null,
                  })
                : t(issue.type);
        return (
            <Card className="overflow-hidden">
                <CardHeader>
                    <CardTitle>{t('sitemap.issues_title')}</CardTitle>
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
                                detail.export_run &&
                                (detail.issues.length > 0 ||
                                    detail.not_verifiable.length > 0) && (
                                    <FindingsExport
                                        onClipboardUnavailable={setManualText}
                                        context={exportContext}
                                        observedRunId={detail.export_run.id}
                                        observedAt={exportContext.checkedAt}
                                        downloadUrl={exportContext.downloadUrl(
                                            detail.export_run.id,
                                            detail.export_run.endedAt,
                                        )}
                                        totalRows={
                                            detail.totalIssuesCount +
                                            detail.not_verifiable.length
                                        }
                                        totalNotVerifiable={
                                            detail.not_verifiable.length
                                        }
                                        groups={[
                                            {
                                                rows: detail.issues.map(
                                                    (issue) => {
                                                        const status =
                                                            issue.kind &&
                                                            issue.kind !==
                                                                'http_status'
                                                                ? t(
                                                                      `sitemap.status.${issue.kind}`,
                                                                  )
                                                                : String(
                                                                      issue.status ??
                                                                          problemText(
                                                                              issue,
                                                                          ),
                                                                  );
                                                        const problem =
                                                            issue.kind ===
                                                                'tls' &&
                                                            !issue.type.startsWith(
                                                                'causes.tls.',
                                                            )
                                                                ? t(
                                                                      'TLS certificate verification failed',
                                                                  )
                                                                : problemText(
                                                                      issue,
                                                                  );
                                                        return {
                                                            url: issue.url,
                                                            result:
                                                                status ===
                                                                problem
                                                                    ? status
                                                                    : `${status} · ${problem}`,
                                                        };
                                                    },
                                                ),
                                            },
                                            {
                                                notVerifiable: true,
                                                rows: detail.not_verifiable.map(
                                                    (finding) => ({
                                                        url: finding.url,
                                                        result: t(
                                                            `sitemap.not_verifiable.${finding.reason}`,
                                                        ),
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
                <CardContent className="space-y-3 text-sm">
                    {presentation &&
                        detail.issue_counts &&
                        (presentation.more_reasons > 0 ||
                            detail.totalIssuesCount > detail.issues.length) && (
                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('sitemap.problem')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {presentation.parts.map(
                                            (part, index) => (
                                                <TableRow
                                                    key={`${part.key}-${index}`}
                                                >
                                                    <TableCell className="text-xs whitespace-normal">
                                                        {format.presentationPart(
                                                            part,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ),
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    <FindingsTable
                        empty={t('sitemap.no_issues')}
                        total={detail.totalIssuesCount}
                        showSource={false}
                        showProblem
                        origin={origin}
                        problemLabel="Problem"
                        rows={detail.issues.map((issue) => ({
                            url: issue.url,
                            status:
                                issue.kind && issue.kind !== 'http_status'
                                    ? t(`sitemap.status.${issue.kind}`)
                                    : (issue.status ?? t(issue.type)),
                            problem:
                                issue.kind === 'tls'
                                    ? t(
                                          issue.type.startsWith('causes.tls.')
                                              ? issue.type
                                              : 'TLS certificate verification failed',
                                      )
                                    : problemText(issue),
                        }))}
                    />
                    <NotVerifiableFindings
                        origin={origin}
                        checkType="sitemap"
                        rows={detail.not_verifiable}
                    />
                </CardContent>
            </Card>
        );
    }
    if (detail?.check_type === 'certificate_health') {
        const certificate = detail.certificate_details;
        const failed = detail.certificate_checks.some((item) => !item.passed);
        const nameMismatch = presentation?.parts.some((part) =>
            part.key.startsWith('causes.tls.name_mismatch'),
        );
        const daysLeft = certificate
            ? Math.ceil(
                  (new Date(certificate.valid_until).getTime() - Date.now()) /
                      86400000,
              )
            : null;
        return (
            <Card className="overflow-hidden">
                <CardHeader>
                    <CardTitle>{t('Certificate served')}</CardTitle>
                    <CardAction>{action}</CardAction>
                </CardHeader>
                <CardContent className="space-y-4">
                    <EvidenceFacts
                        rows={[
                            ...(requestedHost
                                ? [
                                      {
                                          label: t('Requested host'),
                                          value: requestedHost,
                                          mono: true,
                                      },
                                  ]
                                : []),
                            ...(certificate
                                ? [
                                      {
                                          label: t('Certificate names'),
                                          value: [
                                              ...new Set([
                                                  certificate.domain,
                                                  ...certificate.additional_domains.filter(
                                                      (domain) =>
                                                          domain.length > 0,
                                                  ),
                                              ]),
                                          ].join(', '),
                                          mono: true,
                                          failed: nameMismatch,
                                      },
                                      {
                                          label: t('Issuer'),
                                          value: certificate.issuer,
                                      },
                                      {
                                          label: t('Valid'),
                                          value: (
                                              <>
                                                  {format.dateRange(
                                                      certificate.valid_from,
                                                      certificate.valid_until,
                                                      {
                                                          month: 'short',
                                                          day: 'numeric',
                                                          year: 'numeric',
                                                      },
                                                  )}{' '}
                                                  ·{' '}
                                                  {daysLeft !== null && (
                                                      <span className="font-mono tabular-nums">
                                                          {daysLeft < 0
                                                              ? t('Expired')
                                                              : t(
                                                                    'certificate.days_left',
                                                                    {
                                                                        count: daysLeft,
                                                                    },
                                                                )}
                                                      </span>
                                                  )}
                                              </>
                                          ),
                                      },
                                      {
                                          label: t('TLS verification'),
                                          value: t(
                                              certificate.is_valid
                                                  ? 'Valid'
                                                  : 'Invalid',
                                          ),
                                      },
                                  ]
                                : []),
                            ...(detail.certificate_chain_issuers.length
                                ? [
                                      {
                                          label: t('Chain'),
                                          value: detail.certificate_chain_issuers.join(
                                              ' → ',
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                    {failed && presentation?.explanation && (
                        <Alert tone="danger">
                            <AlertTitle>{t('Why this fails')}</AlertTitle>
                            <AlertDescription>
                                {format.summary(
                                    presentation.explanation.key,
                                    presentation.explanation.params,
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                </CardContent>
            </Card>
        );
    }
    if (type === 'uptime' && history?.check_type === 'uptime') {
        const run = history.runs[0];
        const failures = history.recent_failures.filter(
            (failure) =>
                !since ||
                new Date(failure.ended_at).getTime() >=
                    new Date(since).getTime(),
        );
        const note = (row: (typeof history.runs)[number]) =>
            row.reasons.map((reason) => t(reason)).join(' · ');
        return (
            <div className="space-y-4">
                <Card className="overflow-hidden">
                    {cardHeading('Latest attempt')}
                    <CardContent className="space-y-3 text-sm">
                        {run ? (
                            <>
                                <StatusBadge
                                    state={uptimeResultState(run.result)}
                                />
                                <EvidenceFacts
                                    rows={[
                                        ...(run.status_code !== null
                                            ? [
                                                  {
                                                      label: t('Status'),
                                                      value: run.status_text,
                                                      mono: true,
                                                  },
                                              ]
                                            : []),
                                        ...(run.total_time_ms !== null
                                            ? [
                                                  {
                                                      label: t('Duration'),
                                                      value: `${format.milliseconds(run.total_time_ms)} ${t('ms')}`,
                                                      mono: true,
                                                  },
                                              ]
                                            : []),
                                        {
                                            label: t('Last run'),
                                            value: (
                                                <MonitoringTime
                                                    value={run.ended_at}
                                                />
                                            ),
                                            mono: true,
                                        },
                                    ]}
                                />
                                {note(run) && (
                                    <p className="text-muted-foreground">
                                        {note(run)}
                                    </p>
                                )}
                                {presentation?.explanation && (
                                    <p className="text-sm text-muted-foreground">
                                        {format.summary(
                                            presentation.explanation.key,
                                            presentation.explanation.params,
                                        )}
                                    </p>
                                )}
                                {latest && run.status_code === null && (
                                    <p className="text-muted-foreground">
                                        {latest.presentation
                                            ? format.presentation(
                                                  latest.presentation,
                                              )
                                            : latest.summary
                                              ? format.summary(
                                                    latest.summary,
                                                    latest.summary_params,
                                                )
                                              : t('No result')}
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                {t('Awaiting first check result')}
                            </p>
                        )}
                    </CardContent>
                </Card>
                {failures.length > 0 && (
                    <Card className="overflow-hidden">
                        <CardHeader>
                            <CardTitle>{t('Recent failed attempts')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <ul className="divide-y rounded-lg border">
                                {failures.map((failure) => (
                                    <li
                                        key={failure.run_id}
                                        className={cn(
                                            'space-y-2',
                                            listCardPadding,
                                        )}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <MonitoringTime
                                                value={failure.ended_at}
                                                className="font-mono text-xs tabular-nums"
                                            />
                                            <StatusBadge
                                                state={uptimeResultState(
                                                    failure.result,
                                                )}
                                            />
                                            {failure.status_code !== null && (
                                                <span className="font-mono text-xs">
                                                    {failure.status_text}
                                                </span>
                                            )}
                                            {failure.total_time_ms !== null && (
                                                <span className="font-mono text-xs">
                                                    {format.number(
                                                        failure.total_time_ms,
                                                    )}{' '}
                                                    {t('ms')}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground">
                                            {note(failure) || '—'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Response headers and body excerpts were not retained',
                                )}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        );
    }
    if (detail?.check_type === 'domain') {
        return (
            <Card className="overflow-hidden">
                {cardHeading('Domain')}
                <CardContent>
                    <EvidenceFacts
                        rows={[
                            {
                                label: t('Domain'),
                                value: detail.domain,
                                mono: true,
                            },
                            {
                                label: t('Registrar'),
                                value: detail.registrar ?? t('Not retained'),
                            },
                            {
                                label: t('Expires'),
                                value: detail.expires_at
                                    ? format.calendarDate(detail.expires_at)
                                    : t('Not retained'),
                                mono: true,
                            },
                            {
                                label: t('Registered'),
                                value: detail.registered_at
                                    ? format.calendarDate(detail.registered_at)
                                    : t('Not retained'),
                                mono: true,
                            },
                            {
                                label: t('Nameservers'),
                                value: detail.nameservers.join(', '),
                                mono: true,
                            },
                            {
                                label: t('Source'),
                                value: detail.source,
                                mono: true,
                            },
                        ]}
                    />
                </CardContent>
            </Card>
        );
    }
    if (type === 'lighthouse') {
        const snapshot = detail?.check_type === 'lighthouse' ? detail : null;
        const scores = history?.check_type === 'lighthouse' ? history : null;
        const metrics =
            scores?.last_successful_metrics ?? snapshot?.metrics ?? null;
        return (
            <Card className="overflow-hidden">
                {cardHeading('lighthouse.scores')}
                <CardContent className="space-y-4">
                    {presentation?.explanation && (
                        <Alert tone="info">
                            <AlertTitle>{t('Not checked')}</AlertTitle>
                            <AlertDescription>
                                <p>{format.presentation(presentation)}</p>
                                <p>
                                    {format.summary(
                                        presentation.explanation.key,
                                        presentation.explanation.params,
                                    )}
                                </p>
                            </AlertDescription>
                        </Alert>
                    )}
                    {scores?.last_successful_at && (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                latest?.id !== scores.last_successful_run_id
                                    ? 'Scores from last successful run'
                                    : 'Last completed run',
                            )}{' '}
                            ·{' '}
                            <MonitoringTime value={scores.last_successful_at} />
                            {scores.last_successful_device &&
                                ` · ${t(scores.last_successful_device === 'mobile' ? 'Mobile' : 'Desktop')}`}
                        </p>
                    )}
                    <LighthouseScores
                        metrics={metrics}
                        evidence={snapshot?.evidence ?? null}
                    />
                    {!metrics && (
                        <p className="text-sm text-muted-foreground">
                            {t('No evidence retained for this check.')}
                        </p>
                    )}
                </CardContent>
            </Card>
        );
    }
    return (
        <Card className="overflow-hidden">
            {cardHeading('What we see now')}
            <CardContent className="py-6 text-sm text-muted-foreground">
                {t('No evidence retained for this check.')}
            </CardContent>
        </Card>
    );
}
