import { ListFooter } from '@/components/list-footer';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { SiteAvatar } from '@/components/site-avatar';
import { Clock } from 'lucide-react';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useEffect, useRef } from 'react';
import { EmptyState } from '@/components/empty-state';
import {
    filterDefaults,
    filterValues,
    filtersActive,
    HistoryFilters,
    ListFilterBar,
} from '@/components/list-filter-bar';
import { ListHeader, ListRow } from '@/components/ui/table';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { RunBoard } from '@/components/run-board';
import {
    RunResultChip,
    RunStatus,
    runLifecycleWord,
    runOutcome,
} from '@/components/run-family';
import { ResultSummary } from '@/components/result-summary';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/hooks/use-format';
import { checkTypes } from '@/lib/check-types';
import { index as runsIndex } from '@/routes/runs';
import { show as checkShow } from '@/routes/sites/checks';
import { EntityLink } from '@/components/entity-link';
import { TextLink } from '@/components/text-link';

const historyProps = ['runs', 'next_cursor', 'filters', 'before', 'per_page'];

export default function Runs({
    current,
    queued_more,
    finished,
    runs,
    next_cursor,
    filters,
    before,
    per_page,
    attempt_retention_days,
}: Heptapod.Data.Ui.RunsIndexPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    // Merge consecutive live changes before the preceding response arrives.
    const values = filterValues(filters);
    const pendingFilters = useRef(values);
    useEffect(() => {
        pendingFilters.current = filterValues(filters);
    }, [filters]);
    const visitFilters = (
        changes: Record<string, string | null>,
        historyOnly = false,
    ) => {
        const next = { ...pendingFilters.current, ...changes };
        pendingFilters.current = next;
        router.get(
            runsIndex.url(),
            Object.fromEntries(
                Object.entries(next).filter(([, value]) => value !== null),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                ...(historyOnly ? { only: historyProps } : {}),
            },
        );
    };
    const check = filters.check;
    const checkScoped = check != null && check.value !== null;
    const active = filtersActive(filters);
    return (
        <PageContainer>
            <Head title={t('Runs')} />
            <PageHeader
                title={t('Runs')}
                description={t('pages.runs.description')}
            />
            <div data-run-filters>
                <ListFilterBar
                    filters={filters}
                    sources={{
                        clients: { kind: 'clients', scope: { browse: true } },
                    }}
                    disabledReason={
                        checkScoped
                            ? {
                                  id: 'runs-check-scope',
                                  label: t('runs.filters.checkScope'),
                              }
                            : undefined
                    }
                    onChange={visitFilters}
                    onClearFilters={() => visitFilters(filterDefaults(filters))}
                />
            </div>
            <RunBoard
                data={{ current, queued_more, finished }}
                scope={JSON.stringify(
                    Object.entries(filters).flatMap(([key, filter]) =>
                        filter.slot === 'bar' ? [[key, filter.value]] : [],
                    ),
                )}
            />
            <section
                data-run-history
                aria-labelledby="run-history"
                className="@container/table overflow-hidden rounded-lg border"
            >
                <div className="border-b bg-muted/30 px-4 py-3">
                    <SectionHeading id="run-history">
                        {t('runs.screen.history')}
                    </SectionHeading>
                </div>
                {/* The page filter above applies here too; the history only adds its range and the same-result toggle. */}
                <div className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                    <HistoryFilters
                        filters={filters}
                        onChange={(changes) => visitFilters(changes, true)}
                    />
                    <p className="basis-full text-xs text-muted-foreground">
                        {t('runs.history.attemptRetention', {
                            count: attempt_retention_days,
                        })}
                        {check?.detail_url && (
                            <>
                                {' '}
                                <TextLink
                                    document
                                    variant="secondary"
                                    href={check.detail_url}
                                >
                                    {t('runs.history.acceptedResults')}
                                </TextLink>
                            </>
                        )}
                    </p>
                </div>
                {runs.length > 0 && (
                    <ListHeader className="grid-cols-[9rem_7rem_minmax(0,1fr)_5rem_minmax(0,1.3fr)] lg:grid">
                        {[
                            t('Time'),
                            t('State'),
                            t('runs.screen.checkSite'),
                            t('runs.screen.took'),
                            t('Note'),
                        ].map((label) => (
                            <span key={label}>{label}</span>
                        ))}
                    </ListHeader>
                )}
                {runs.length === 0 ? (
                    <EmptyState
                        bare
                        icon={Clock}
                        title={t('No attempts in the last 24 hours.')}
                        description={t(
                            'Every check run appears here with its result.',
                        )}
                        filtered={{
                            active,
                            title: t('runs.screen.noHistory'),
                            description: t(
                                'Clear filters to see the last 24 hours.',
                            ),
                        }}
                    />
                ) : (
                    runs.map((row) => (
                        <ListRow
                            key={row.id}
                            className="gap-3 lg:grid-cols-[9rem_7rem_minmax(0,1fr)_5rem_minmax(0,1.3fr)] lg:items-center"
                        >
                            <AbsoluteTime
                                value={row.requested_at}
                                label={t('Time')}
                                mode="eventTimestamp"
                                className="font-mono text-xs text-muted-foreground tabular-nums"
                            />
                            <div>
                                {row.result !== null ? (
                                    <RunResultChip
                                        outcome={runOutcome(row.result)}
                                    />
                                ) : row.run.phase === 'queued' ||
                                  row.run.phase === 'running' ? (
                                    <RunStatus phase={row.run.phase} />
                                ) : (
                                    // Ended without a result: the Note column says why.
                                    <span className="text-xs text-muted-foreground">
                                        {t(runLifecycleWord.finished)}
                                    </span>
                                )}
                            </div>
                            <span className="min-w-0">
                                <EntityLink
                                    name={t(checkTypes[row.check_type].label)}
                                    href={checkShow.url({
                                        site: row.site_id,
                                        check: row.check_id,
                                    })}
                                />
                                <span className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                                    <SiteAvatar
                                        name={row.site_name}
                                        iconUrl={row.icon_url}
                                        iconBacking={row.icon_backing}
                                    />
                                    <span className="truncate">
                                        {row.site_name}
                                    </span>
                                </span>
                            </span>
                            <span className="font-mono text-xs">
                                {row.duration_ms === null
                                    ? '—'
                                    : `${format.number(row.duration_ms)} ms`}
                            </span>
                            <div className="min-w-0 text-xs text-muted-foreground">
                                {row.summary ? (
                                    <ResultSummary
                                        message={row.summary}
                                        params={row.summary_params}
                                        presentation={row.presentation}
                                    />
                                ) : (
                                    row.note
                                )}
                            </div>
                        </ListRow>
                    ))
                )}
                {runs.length > 0 && (
                    <div className="border-t px-4 py-3">
                        <ListFooter
                            count={t('runs.board.historyRows', {
                                count: runs.length,
                                formattedCount: format.number(runs.length),
                            })}
                            extra={
                                before !== null && (
                                    <Button
                                        variant="outline"
                                        onClick={() => visitFilters({}, true)}
                                    >
                                        {t('runs.board.newestRuns')}
                                    </Button>
                                )
                            }
                            next={
                                next_cursor
                                    ? {
                                          href: runsIndex.url({
                                              query: {
                                                  ...values,
                                                  before: next_cursor,
                                              },
                                          }),
                                          only: historyProps,
                                          preserveState: true,
                                      }
                                    : null
                            }
                            pageSize={per_page}
                        />
                    </div>
                )}
            </section>
        </PageContainer>
    );
}
