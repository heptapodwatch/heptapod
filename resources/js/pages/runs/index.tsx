import { ListFooter } from '@/components/list-footer';
import { CheckboxField } from '@/components/ui/checkbox';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { SiteAvatar } from '@/components/site-avatar';
import { Clock } from 'lucide-react';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useEffect, useRef } from 'react';
import { EmptyState } from '@/components/empty-state';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { SegmentedControl } from '@/components/segmented-control';
import { ListHeader, ListRow } from '@/components/ui/table';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { RunBoard, RunResultChip } from '@/components/run-board';
import { RunBar } from '@/components/foundation-primitives';
import { ResultSummary } from '@/components/result-summary';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/hooks/use-format';
import { checkTypes } from '@/lib/check-types';
import { index as runsIndex } from '@/routes/runs';
import { show as checkShow } from '@/routes/sites/checks';
import { EntityLink } from '@/components/entity-link';

type Phase = Heptapod.Data.Ui.CheckRunStateData['phase'];
const phases: Record<Phase, string> = {
    scheduled: 'Scheduled',
    queued: 'Queued',
    running: 'Running',
    late: 'Late',
    done: 'Done',
    failed: 'Failed',
    skipped: 'Skipped',
    cancelled: 'Cancelled',
};
function isCheckType(value: string): value is Heptapod.Contracts.CheckKey {
    return Object.hasOwn(checkTypes, value);
}
function isPhase(value: string): value is Phase {
    return Object.hasOwn(phases, value);
}

export default function Runs({
    current,
    queued_more,
    finished,
    runs,
    next_cursor,
    clients,
    filters,
}: Heptapod.Data.Ui.RunsBoardData &
    Heptapod.Data.Ui.RunsHistoryPageData & {
        clients: Heptapod.Data.Ui.ClientData[];
        filters: Heptapod.Data.Ui.RunsFilterData;
    }) {
    const { t } = useTranslation();
    const format = useFormat();
    // Merge consecutive live changes before the preceding response arrives.
    const pendingFilters = useRef(filters);
    useEffect(() => {
        pendingFilters.current = filters;
    }, [filters]);
    const visitFilters = (
        changes: Partial<Heptapod.Data.Ui.RunsFilterData>,
        historyOnly = false,
    ) => {
        const next = {
            ...pendingFilters.current,
            ...changes,
            before: changes.before ?? null,
        };
        pendingFilters.current = next;
        router.get(
            runsIndex.url(),
            Object.fromEntries(
                Object.entries({
                    ...next,
                    show_same_result: next.show_same_result ? 1 : undefined,
                }).filter(([, value]) => value !== null && value !== undefined),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                ...(historyOnly
                    ? { only: ['runs', 'next_cursor', 'filters'] }
                    : {}),
            },
        );
    };
    // activeCount counts the page filters inside the mobile collapse; the history range lives outside it but narrows
    // the history too. The same-result toggle defaults to hiding repeats, so turning it on only widens and never counts.
    const runFilterCount = [
        filters.client,
        filters.check,
        filters.type,
        filters.state,
    ].filter(Boolean).length;
    const filtersActive = runFilterCount > 0 || filters.period !== '24h';
    const clearRunFilters = () =>
        visitFilters({
            client: null,
            check: null,
            type: null,
            state: null,
            period: '24h',
        });
    return (
        <PageContainer>
            <Head title={t('Runs')} />
            <PageHeader
                title={t('Runs')}
                description={t('pages.runs.description')}
            />
            <div data-run-filters>
                <FilterBar
                    activeCount={runFilterCount}
                    filtersActive={filtersActive}
                    onClearFilters={clearRunFilters}
                >
                    <FilterCombobox
                        ariaLabel={t('Client')}
                        options={[
                            { value: 'all', label: t('All clients') },
                            ...clientComboboxOptions(clients),
                        ]}
                        value={filters.client?.toString() ?? 'all'}
                        onValueChange={(value) =>
                            visitFilters({
                                client: value === 'all' ? null : Number(value),
                            })
                        }
                        onClear={
                            filters.client !== null
                                ? () => visitFilters({ client: null })
                                : null
                        }
                    />
                    <Select
                        value={filters.type ?? 'all'}
                        onValueChange={(value) => {
                            if (value !== 'all' && !isCheckType(value))
                                throw new Error('Unknown check type: ' + value);
                            visitFilters({
                                type: value === 'all' ? null : value,
                            });
                        }}
                    >
                        <FilterSelectTrigger
                            aria-label={t('Check type')}
                            onClear={
                                filters.type !== null
                                    ? () => visitFilters({ type: null })
                                    : null
                            }
                        >
                            <SelectValue />
                        </FilterSelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('All checks')}
                            </SelectItem>
                            {Object.entries(checkTypes).map(
                                ([value, check]) => (
                                    <SelectItem key={value} value={value}>
                                        {t(check.label)}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.state ?? 'all'}
                        onValueChange={(value) => {
                            if (value !== 'all' && !isPhase(value))
                                throw new Error('Unknown run state: ' + value);
                            visitFilters({
                                state: value === 'all' ? null : value,
                            });
                        }}
                    >
                        <FilterSelectTrigger
                            aria-label={t('State')}
                            onClear={
                                filters.state !== null
                                    ? () => visitFilters({ state: null })
                                    : null
                            }
                        >
                            <SelectValue />
                        </FilterSelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('runs.screen.allStates')}
                            </SelectItem>
                            {Object.entries(phases).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {t(label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FilterBar>
            </div>
            <RunBoard
                data={{ current, queued_more, finished }}
                filters={filters}
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
                    <SegmentedControl
                        label={t('runs.board.historyRange')}
                        value={filters.period}
                        onValueChange={(period) => {
                            if (period !== '24h' && period !== '7d')
                                throw new Error(
                                    'Unknown runs period: ' + period,
                                );
                            visitFilters({ period }, true);
                        }}
                        options={[
                            { value: '24h', label: t('Last 24 hours') },
                            { value: '7d', label: t('Last 7 days') },
                        ]}
                    />
                    <CheckboxField
                        checked={filters.show_same_result}
                        onCheckedChange={(checked) =>
                            visitFilters(
                                { show_same_result: checked === true },
                                true,
                            )
                        }
                    >
                        {t('Show runs with the same result as before')}
                    </CheckboxField>
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
                            active: filtersActive,
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
                                        check={row.check_type}
                                        result={row.result}
                                    />
                                ) : (
                                    <RunBar
                                        state={row.run.phase}
                                        description={null}
                                        compact
                                    />
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
                                filters.before !== null && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visitFilters({ before: null }, true)
                                        }
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
                                                  ...filters,
                                                  show_same_result:
                                                      filters.show_same_result
                                                          ? 1
                                                          : undefined,
                                                  before: next_cursor,
                                              },
                                          }),
                                          only: [
                                              'runs',
                                              'next_cursor',
                                              'filters',
                                          ],
                                          preserveState: true,
                                      }
                                    : null
                            }
                        />
                    </div>
                )}
            </section>
        </PageContainer>
    );
}
