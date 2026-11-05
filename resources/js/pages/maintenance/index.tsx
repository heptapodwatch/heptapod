import { ListFooter } from '@/components/list-footer';
import { TruncatedText } from '@/components/truncated-text';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AbsoluteTime } from '@/components/absolute-time';
import { ClientMark } from '@/components/client-mark';
import { CalendarDays } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { RowActions } from '@/components/row-actions';
import { FilterBar, FilterCombobox } from '@/components/filter-bar';
import {
    EntityCombobox,
    clientComboboxOptions,
    siteComboboxOptions,
} from '@/components/entity-combobox';
import { TimezoneCombobox } from '@/components/timezone-combobox';
import { ListHeader, ListRow } from '@/components/ui/table';
import { FormSecondaryAction } from '@/components/form-section';
import { SheetForm } from '@/components/sheet-form';
import { PageHeader } from '@/components/page-header';
import { PageContainer } from '@/components/page-container';
import { SegmentedControl } from '@/components/segmented-control';
import { Head, router } from '@inertiajs/react';
import { show as siteShow } from '@/routes/sites';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { PendingChanges } from '@/components/pending-changes';
import InputError, { fieldErrorProps } from '@/components/input-error';
import {
    NumberField,
    type NumberFieldValue,
} from '@/components/ui/number-field';
import { WeekdayToggleGroup } from '@/components/ui/weekday-toggle-group';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { DateTimeField, TimeField } from '@/components/ui/date-time-field';
import { zonedWallTime } from '@/lib/date-time';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Sheet } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useBufferedChanges } from '@/hooks/use-buffered-changes';
import { useFormat } from '@/hooks/use-format';
import { useLiveReload } from '@/hooks/use-live';
import {
    destroy as cancelWindow,
    end as endWindow,
    index as maintenanceIndex,
    store as createWindow,
    update as updateWindow,
} from '@/routes/maintenance';
import {
    destroy as cancelSeries,
    update as updateSeries,
} from '@/routes/maintenance/series';
import { TextLink } from '@/components/text-link';

type Window = Heptapod.Data.Ui.MaintenanceWindowData;
type Target = Heptapod.Data.Ui.MaintenanceTargetOptionData;
type Draft = {
    target_type: 'site' | 'client' | 'tag';
    target_id: string;
    starts_local: string;
    ends_local: string;
    timezone: string;
    recurrence_type: 'once' | 'daily' | 'weekly' | 'monthly';
    /** Unparsable typed text stays a string and goes to the server, which rejects it. */
    interval: NumberFieldValue;
    days_of_week: number[];
    day_of_month: NumberFieldValue;
    nth_weekday: number;
    day_of_week: number;
    name: string;
    reason: string;
    mute_alerts: boolean;
    catch_up_at_end: boolean;
};

function recurringEnd(startsLocal: string, endTime: string): string {
    const day = new Date(`${startsLocal.slice(0, 10)}T00:00:00Z`);
    if (endTime <= startsLocal.slice(11)) day.setUTCDate(day.getUTCDate() + 1);
    return `${day.toISOString().slice(0, 10)} ${endTime}`;
}

function newWindowRequested(): boolean {
    return (
        typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).get('new') === '1'
    );
}

function freshDraft(timezone: string): Draft {
    const start = new Date(Date.now() + 3600000);
    return {
        target_type: 'site',
        target_id: '',
        starts_local: zonedWallTime(start.toISOString(), timezone),
        ends_local: zonedWallTime(
            new Date(start.getTime() + 3600000).toISOString(),
            timezone,
        ),
        timezone,
        recurrence_type: 'once',
        interval: 1,
        days_of_week: [0],
        day_of_month: 1,
        nth_weekday: 2,
        day_of_week: 0,
        name: '',
        reason: '',
        mute_alerts: true,
        catch_up_at_end: true,
    };
}

/** One verb per maintenance action, chosen by whether the window belongs to a series (heptapod-ui-rules Confirmations). */
function maintenanceActionCopy(
    action: 'end_now' | 'cancel_occurrence' | 'cancel_series',
    inSeries: boolean,
): { label: string; title: string; description: string } {
    if (action === 'end_now')
        return {
            label: 'End window',
            title: 'End this window now?',
            description:
                'Its sites leave maintenance now. Other windows keep running.',
        };
    if (action === 'cancel_series')
        return {
            label: 'Delete series',
            title: 'Delete this series?',
            description:
                'Future occurrences are deleted. A running occurrence keeps going until it ends.',
        };
    return inSeries
        ? {
              label: 'Skip occurrence',
              title: 'Skip this occurrence?',
              description:
                  'Only this occurrence is deleted. The series keeps its other dates.',
          }
        : {
              label: 'Delete window',
              title: 'Delete this window?',
              description:
                  'The planned window is deleted. This cannot be undone.',
          };
}

export default function MaintenanceIndex({
    windows,
    next_page,
    previous_page,
    total,
    state,
    site_name,
    can_manage,
    instance_timezone,
    timezones,
    sites,
    clients,
    tags,
    filter_clients,
    client_id,
    site_id,
}: Heptapod.Data.Ui.MaintenanceIndexPageData) {
    const { t, i18n } = useTranslation();
    const format = useFormat();
    useLiveReload(
        ['state.changed'],
        ['windows', 'next_page', 'previous_page', 'total'],
        undefined,
        true,
        {
            missingResource:
                site_id === null
                    ? undefined
                    : {
                          url: maintenanceIndex.url(),
                          message: t('Site deleted'),
                      },
        },
    );
    const buffered = useBufferedChanges(
        windows,
        (window) => window.id,
        null,
        (window) => JSON.stringify(window),
        'windows',
    );
    // The site header's "Schedule maintenance" opens this sheet with the site preselected (?site_id=…&new=1).
    const [sheetOpen, setSheetOpen] = useState(
        () => can_manage && site_id !== null && newWindowRequested(),
    );
    useEffect(() => {
        if (!newWindowRequested()) return;
        // Consume the one-shot request so Back or a reload shows the list, not a second sheet.
        const url = new URL(window.location.href);
        url.searchParams.delete('new');
        router.replace({
            url: url.pathname + url.search,
            preserveState: true,
            preserveScroll: true,
        });
    }, []);
    const [editing, setEditing] = useState<Window | null>(null);
    const [draft, setDraft] = useState<Draft>(() =>
        sheetOpen && site_id !== null
            ? { ...freshDraft(instance_timezone), target_id: String(site_id) }
            : freshDraft(instance_timezone),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const endError =
        errors.ends_local ??
        errors.end_time ??
        (errors.ends_at
            ? t('Choose an end date after the start date.')
            : undefined);
    const [startsInvalid, setStartsInvalid] = useState(false);
    const [endsInvalid, setEndsInvalid] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [confirmation, setConfirmation] = useState<{
        window: Window;
        action: 'end_now' | 'cancel_occurrence' | 'cancel_series';
    } | null>(null);
    const rows = buffered.rows;
    const choices: Target[] =
        draft.target_type === 'site'
            ? sites
            : draft.target_type === 'client'
              ? clients
              : tags;
    const change = <K extends keyof Draft>(key: K, value: Draft[K]) =>
        setDraft((current) => ({ ...current, [key]: value }));
    const openSheet = (window: Window | null) => {
        let formTimezone = window?.timezone ?? instance_timezone;
        if (window?.seriesId) {
            if (
                !window.startTime ||
                !window.endTime ||
                !window.seriesTimezone
            ) {
                throw new Error(
                    'Maintenance series is missing its stored times or timezone.',
                );
            }
            formTimezone = window.seriesTimezone;
        }
        setEditing(window);
        setDraft(
            window
                ? {
                      ...freshDraft(formTimezone),
                      target_type: window.targetType as Draft['target_type'],
                      target_id: String(window.targetId),
                      starts_local: window.seriesId
                          ? `${window.anchorDate ?? zonedWallTime(window.startsAt, formTimezone).slice(0, 10)}T${window.startTime}`
                          : zonedWallTime(window.startsAt, window.timezone),
                      ends_local: window.seriesId
                          ? window.endTime!
                          : zonedWallTime(window.endsAt, window.timezone),
                      timezone: formTimezone,
                      recurrence_type: (window.recurrenceType ??
                          'once') as Draft['recurrence_type'],
                      interval: window.interval,
                      days_of_week: window.daysOfWeek ?? [
                          window.dayOfWeek ?? 0,
                      ],
                      day_of_month: window.dayOfMonth ?? 1,
                      nth_weekday:
                          window.nthWeekday ?? (window.dayOfMonth ? 0 : 2),
                      day_of_week: window.dayOfWeek ?? 0,
                      name: window.name ?? '',
                      reason: window.reason ?? '',
                      mute_alerts: window.muteAlerts,
                      catch_up_at_end: window.catchUpAtEnd,
                  }
                : freshDraft(instance_timezone),
        );
        setErrors({});
        setStartsInvalid(false);
        setEndsInvalid(false);
        setSheetOpen(true);
    };
    const returnOptions = {
        query: {
            return_context: {
                state,
                ...(site_id === null ? {} : { site_id }),
                ...(client_id === null ? {} : { client_id }),
                page: previous_page === null ? 1 : previous_page + 1,
            },
        },
    };
    const submit = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Unparsable typed input fails loud; never send the stale value.
        if (startsInvalid || endsInvalid) return;
        const base = {
            target_type: draft.target_type,
            target_id: Number(draft.target_id),
            name: draft.name || null,
            reason: draft.reason || null,
            timezone: draft.timezone,
            mute_alerts: draft.mute_alerts,
            catch_up_at_end: draft.catch_up_at_end,
        };
        const recurrence = {
            recurrence_type: draft.recurrence_type,
            interval: draft.interval,
            ...(draft.recurrence_type === 'weekly'
                ? { days_of_week: draft.days_of_week }
                : draft.recurrence_type === 'monthly'
                  ? draft.nth_weekday > 0
                      ? {
                            nth_weekday: draft.nth_weekday,
                            day_of_week: draft.day_of_week,
                        }
                      : { day_of_month: draft.day_of_month }
                  : {}),
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
            onError: (response: Record<string, string>) => setErrors(response),
            onFinish: () => setProcessing(false),
        };
        setProcessing(true);
        if (editing?.seriesId) {
            if (!editing.seriesTimezone) {
                throw new Error(
                    'Maintenance series is missing its stored timezone.',
                );
            }
            router.put(
                updateSeries.url(editing.seriesId, returnOptions),
                {
                    ...base,
                    days_of_week: null,
                    nth_weekday: null,
                    day_of_week: null,
                    day_of_month: null,
                    ...recurrence,
                    // A legacy null anchor uses the occurrence date for display only. A no-op must leave it null.
                    ...(editing.anchorDate !== null ||
                    draft.starts_local.slice(0, 10) !==
                        zonedWallTime(
                            editing.startsAt,
                            editing.seriesTimezone,
                        ).slice(0, 10)
                        ? { anchor_date: draft.starts_local.slice(0, 10) }
                        : {}),
                    start_time: draft.starts_local.slice(11),
                    end_time: draft.ends_local,
                },
                options,
            );
        } else if (editing) {
            router.put(
                updateWindow.url(editing.id, returnOptions),
                {
                    ...base,
                    starts_local: draft.starts_local.replace('T', ' '),
                    ends_local: draft.ends_local.replace('T', ' '),
                },
                options,
            );
        } else {
            router.post(
                createWindow.url(returnOptions),
                {
                    ...base,
                    ...recurrence,
                    starts_local: draft.starts_local.replace('T', ' '),
                    ends_local:
                        draft.recurrence_type === 'once'
                            ? draft.ends_local.replace('T', ' ')
                            : recurringEnd(
                                  draft.starts_local,
                                  draft.ends_local,
                              ),
                },
                options,
            );
        }
    };
    const confirmationCopy = maintenanceActionCopy(
        confirmation?.action ?? 'end_now',
        confirmation?.window.seriesId != null,
    );
    const confirmAction = () =>
        confirmVisit((options) => {
            if (!confirmation)
                throw new Error('Maintenance confirmation has no action.');
            const { window, action } = confirmation;
            if (action === 'end_now')
                router.post(
                    endWindow.url(window.id, returnOptions),
                    {},
                    options,
                );
            else if (action === 'cancel_series' && window.seriesId)
                router.delete(
                    cancelSeries.url(window.seriesId, returnOptions),
                    options,
                );
            else
                router.delete(
                    cancelWindow.url(window.id, returnOptions),
                    options,
                );
        });
    const stateLabel = (window: Window) =>
        t(
            window.state === 'active'
                ? 'Active'
                : window.state === 'planned'
                  ? 'Planned'
                  : 'Done',
        );
    const stateMark = (window: Window) => (
        <span className="inline-flex items-center gap-2 whitespace-nowrap">
            <StatusDot
                state={
                    window.state === 'active'
                        ? 'maintenance'
                        : window.state === 'planned'
                          ? 'pending'
                          : 'ok'
                }
                label={stateLabel(window)}
            />
            {stateLabel(window)}
        </span>
    );
    const pageLink = (page: number | null) =>
        page === null
            ? null
            : {
                  href: maintenanceIndex.url({
                      query: {
                          page,
                          client_id: client_id ?? undefined,
                          site_id: site_id ?? undefined,
                          state,
                      },
                  }),
              };
    return (
        <PageContainer>
            <Head title={t('Maintenance')} />
            <PageHeader
                title={t('Maintenance')}
                // A site-scoped view names its site in the shared trail; Clear filters returns to all windows.
                trail={
                    site_id !== null ? (
                        <TextLink
                            variant="secondary"
                            href={siteShow.url(site_id)}
                            prefetch
                        >
                            {site_name}
                        </TextLink>
                    ) : undefined
                }
                description={t('pages.maintenance.description')}
                actions={
                    <>
                        {can_manage && (
                            <Button
                                data-shortcut-create
                                onClick={() => openSheet(null)}
                            >
                                {t('New window')}
                            </Button>
                        )}
                    </>
                }
            />
            <FilterBar
                activeCount={
                    [client_id, site_id, state !== 'all'].filter(Boolean).length
                }
                filtersActive={
                    client_id !== null || site_id !== null || state !== 'all'
                }
                onClearFilters={() => router.get(maintenanceIndex.url())}
                segmented={
                    <SegmentedControl
                        label={t('Window state')}
                        value={state}
                        onValueChange={(value) =>
                            router.get(
                                maintenanceIndex.url(),
                                {
                                    state: value,
                                    client_id: client_id ?? undefined,
                                    site_id: site_id ?? undefined,
                                },
                                { preserveState: true, preserveScroll: true },
                            )
                        }
                        options={[
                            { value: 'all', label: t('All') },
                            { value: 'active', label: t('Active') },
                            { value: 'planned', label: t('Planned') },
                            { value: 'done', label: t('Done') },
                        ]}
                    />
                }
            >
                <FilterCombobox
                    id="maintenance-client-filter"
                    ariaLabel={t('Client')}
                    options={[
                        { value: 'all', label: t('All clients') },
                        ...clientComboboxOptions(filter_clients),
                    ]}
                    value={client_id?.toString() ?? 'all'}
                    onValueChange={(value) =>
                        router.get(
                            maintenanceIndex.url(),
                            {
                                client_id:
                                    value === 'all' ? undefined : Number(value),
                                site_id: site_id ?? undefined,
                                state,
                            },
                            {
                                preserveState: true,
                                preserveScroll: true,
                            },
                        )
                    }
                    onClear={
                        client_id !== null
                            ? () =>
                                  router.get(
                                      maintenanceIndex.url(),
                                      { site_id: site_id ?? undefined, state },
                                      {
                                          preserveState: true,
                                          preserveScroll: true,
                                      },
                                  )
                            : null
                    }
                />
            </FilterBar>
            <PendingChanges
                count={buffered.pendingCount}
                apply={buffered.apply}
            />
            {rows.length === 0 ? (
                <EmptyState
                    icon={CalendarDays}
                    title={t('No maintenance windows yet.')}
                    description={t(
                        'Plan a window to mark planned work on your sites.',
                    )}
                    action={
                        can_manage && (
                            <Button onClick={() => openSheet(null)}>
                                {t('New window')}
                            </Button>
                        )
                    }
                    filtered={{
                        active:
                            client_id !== null ||
                            site_id !== null ||
                            state !== 'all',
                        title: t('No matching maintenance windows.'),
                        description: t('Clear filters to see all windows.'),
                    }}
                />
            ) : (
                <div className="@container/table overflow-hidden rounded-lg border">
                    <ListHeader className="grid-cols-[7rem_minmax(12rem,1fr)_minmax(10rem,1fr)_minmax(12rem,1fr)_auto] xl:grid">
                        <span>{t('State')}</span>
                        <span>{t('When')}</span>
                        <span>{t('Sites')}</span>
                        <span>{t('Effect')}</span>
                        <span className="sr-only">{t('Actions')}</span>
                    </ListHeader>
                    {rows.map((window) => (
                        <ListRow
                            key={window.id}
                            className="xl:grid-cols-[7rem_minmax(12rem,1fr)_minmax(10rem,1fr)_minmax(12rem,1fr)_auto] xl:items-center"
                        >
                            {stateMark(window)}
                            <div className="min-w-0 space-y-1 font-mono text-xs tabular-nums">
                                <span className="flex flex-wrap items-center gap-1">
                                    <AbsoluteTime
                                        value={window.startsAt}
                                        timezone={window.timezone}
                                        label={t('Starts')}
                                    />
                                    <span>–</span>
                                    <AbsoluteTime
                                        value={window.endsAt}
                                        timezone={window.timezone}
                                        label={t('Ends')}
                                    />
                                </span>
                                <span className="block text-muted-foreground">
                                    {window.timezone} ·{' '}
                                    {window.recurrenceType === 'monthly'
                                        ? t('Every {{count}} months', {
                                              count: window.interval,
                                              formattedCount: format.number(
                                                  window.interval,
                                              ),
                                          })
                                        : window.recurrenceType
                                          ? t('Repeats {{type}}', {
                                                type: t(window.recurrenceType),
                                            })
                                          : t('Once')}
                                </span>
                            </div>
                            <div className="min-w-0">
                                <TruncatedText
                                    label={t('Target')}
                                    content={window.targetLabel ?? undefined}
                                >
                                    <span className="flex items-center gap-2 truncate">
                                        {window.targetType === 'client' &&
                                            filter_clients
                                                .filter(
                                                    (client) =>
                                                        client.id ===
                                                        window.targetId,
                                                )
                                                .map(
                                                    (client) =>
                                                        client.avatar && (
                                                            <ClientMark
                                                                key={client.id}
                                                                client={{
                                                                    ...client,
                                                                    avatar: client.avatar,
                                                                }}
                                                                small
                                                            />
                                                        ),
                                                )}
                                        {window.targetLabel ??
                                            t('Target unavailable')}
                                    </span>
                                </TruncatedText>
                                <span className="text-xs text-muted-foreground">
                                    {t('{{count}} sites', {
                                        count: window.siteCount,
                                        formattedCount: format.number(
                                            window.siteCount,
                                        ),
                                    })}
                                </span>
                            </div>
                            <div className="min-w-0 text-xs text-muted-foreground">
                                {window.reason && (
                                    <TruncatedText
                                        label={t('Reason')}
                                        content={window.reason}
                                    >
                                        <span className="block truncate">
                                            {window.reason}
                                        </span>
                                    </TruncatedText>
                                )}
                                <span>
                                    {t(
                                        window.muteAlerts
                                            ? 'Alerts muted'
                                            : 'Alerts continue',
                                    )}
                                </span>
                                {window.catchUpAtEnd && (
                                    <span>
                                        {' '}
                                        ·{' '}
                                        {t('Alert if still failing at the end')}
                                    </span>
                                )}
                            </div>
                            <div className="flex xl:justify-end">
                                <RowActions
                                    label={
                                        window.name ??
                                        window.targetLabel ??
                                        t('Maintenance window')
                                    }
                                    actions={[
                                        ...(window.actions.includes('update')
                                            ? [
                                                  {
                                                      label: t('Edit'),
                                                      onSelect: () =>
                                                          openSheet(window),
                                                  },
                                              ]
                                            : []),
                                        ...(
                                            [
                                                'end_now',
                                                'cancel_occurrence',
                                                'cancel_series',
                                            ] as const
                                        )
                                            .filter((action) =>
                                                window.actions.includes(action),
                                            )
                                            .map((action) => ({
                                                label: t(
                                                    maintenanceActionCopy(
                                                        action,
                                                        window.seriesId != null,
                                                    ).label,
                                                ),
                                                destructive:
                                                    action !== 'end_now',
                                                onSelect: () =>
                                                    setConfirmation({
                                                        window,
                                                        action,
                                                    }),
                                            })),
                                    ]}
                                />
                            </div>
                        </ListRow>
                    ))}
                </div>
            )}
            {total > 0 && (
                <ListFooter
                    count={t('{{count}} windows', {
                        count: total,
                        formattedCount: format.number(total),
                    })}
                    previous={pageLink(previous_page)}
                    next={pageLink(next_page)}
                />
            )}
            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetForm
                    title={t(
                        editing
                            ? 'Edit maintenance window'
                            : 'New maintenance window',
                    )}
                    description={t('Checks keep running during this window.')}
                    id="maintenance-form"
                    onSubmit={submit}
                    footer={
                        <>
                            <FormSecondaryAction
                                onClick={() => setSheetOpen(false)}
                            >
                                {t('Cancel')}
                            </FormSecondaryAction>
                            <Button
                                type="submit"
                                form="maintenance-form"
                                disabled={processing || !draft.target_id}
                            >
                                {t(
                                    processing
                                        ? 'Saving…'
                                        : editing
                                          ? 'Save'
                                          : 'Schedule maintenance',
                                )}
                            </Button>
                        </>
                    }
                >
                    <div className="space-y-1">
                        <Label id="maintenance-target-type-label">
                            {t('Target')}
                        </Label>
                        <RadioGroup
                            id="maintenance-target-type"
                            aria-labelledby="maintenance-target-type-label"
                            {...fieldErrorProps(
                                'maintenance-target-type',
                                errors.target_type,
                            )}
                            value={draft.target_type}
                            onValueChange={(value: Draft['target_type']) => {
                                change('target_type', value);
                                change('target_id', '');
                            }}
                        >
                            <RadioGroupItem value="site">
                                {t('Site')}
                            </RadioGroupItem>
                            <RadioGroupItem value="client">
                                {t('Client')}
                            </RadioGroupItem>
                            <RadioGroupItem value="tag">
                                {t('Tag')}
                            </RadioGroupItem>
                        </RadioGroup>
                        <InputError
                            field="maintenance-target-type"
                            message={errors.target_type}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="maintenance-target">
                            {t(
                                draft.target_type === 'site'
                                    ? 'Sites'
                                    : draft.target_type === 'client'
                                      ? 'Clients'
                                      : 'Tags',
                            )}
                        </Label>
                        <EntityCombobox
                            id="maintenance-target"
                            invalid={!!errors.target_id}
                            describedBy={
                                fieldErrorProps(
                                    'maintenance-target',
                                    errors.target_id,
                                )['aria-describedby']
                            }
                            placeholder={t('Select target')}
                            options={
                                draft.target_type === 'site'
                                    ? siteComboboxOptions(choices)
                                    : draft.target_type === 'client'
                                      ? clientComboboxOptions(choices)
                                      : choices.map((choice) => ({
                                            value: String(choice.id),
                                            label: choice.name,
                                        }))
                            }
                            value={draft.target_id}
                            onValueChange={(value) =>
                                change('target_id', value)
                            }
                        />
                        {draft.target_id && draft.target_type !== 'site' && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Sites added later join this window automatically.',
                                )}
                            </p>
                        )}
                        <InputError
                            field="maintenance-target"
                            message={errors.target_id}
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="maintenance-start">
                                {t('Starts')}
                            </Label>
                            <DateTimeField
                                id="maintenance-start"
                                required
                                timezone={draft.timezone}
                                invalid={
                                    startsInvalid ||
                                    !!(
                                        errors.starts_local ||
                                        errors.anchor_date ||
                                        errors.start_time
                                    )
                                }
                                describedBy="maintenance-start-error"
                                onInvalidChange={setStartsInvalid}
                                value={draft.starts_local}
                                onValueChange={(value) =>
                                    change('starts_local', value)
                                }
                            />
                            <InputError
                                id="maintenance-start-error"
                                message={
                                    startsInvalid
                                        ? 'Enter the date and time in 24-hour format.'
                                        : (errors.starts_local ??
                                          errors.anchor_date ??
                                          errors.start_time)
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="maintenance-end">{t('Ends')}</Label>
                            {draft.recurrence_type === 'once' ? (
                                <DateTimeField
                                    id="maintenance-end"
                                    invalid={endsInvalid || !!endError}
                                    describedBy="maintenance-end-error"
                                    onInvalidChange={setEndsInvalid}
                                    required
                                    timezone={draft.timezone}
                                    value={draft.ends_local}
                                    onValueChange={(value) =>
                                        change('ends_local', value)
                                    }
                                />
                            ) : (
                                <TimeField
                                    id="maintenance-end"
                                    invalid={endsInvalid || !!endError}
                                    describedBy="maintenance-end-error"
                                    onInvalidChange={setEndsInvalid}
                                    required
                                    value={draft.ends_local}
                                    onValueChange={(value) =>
                                        change('ends_local', value)
                                    }
                                />
                            )}
                            {draft.recurrence_type !== 'once' &&
                                draft.ends_local <=
                                    draft.starts_local.slice(11) && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('Next day')}
                                    </p>
                                )}
                            <InputError
                                id="maintenance-end-error"
                                message={
                                    endsInvalid
                                        ? draft.recurrence_type === 'once'
                                            ? 'Enter the date and time in 24-hour format.'
                                            : 'Enter the time in 24-hour format (HH:MM).'
                                        : endError
                                }
                            />
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="maintenance-timezone">
                            {t('Timezone')}
                        </Label>
                        <TimezoneCombobox
                            id="maintenance-timezone"
                            timezones={timezones}
                            pinned={[instance_timezone]}
                            value={String(draft.timezone)}
                            onValueChange={(value) => change('timezone', value)}
                            invalid={!!errors.timezone}
                            describedBy={
                                errors.timezone
                                    ? 'maintenance-timezone-error'
                                    : undefined
                            }
                        />
                        <InputError
                            id="maintenance-timezone-error"
                            message={errors.timezone}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label id="maintenance-repeat-label">
                            {t('Repeats')}
                        </Label>
                        <RadioGroup
                            id="maintenance-repeat"
                            aria-labelledby="maintenance-repeat-label"
                            {...fieldErrorProps(
                                'maintenance-repeat',
                                errors.recurrence_type,
                                editing !== null && 'maintenance-repeat-locked',
                            )}
                            disabled={editing !== null}
                            value={draft.recurrence_type}
                            onValueChange={(
                                value: Draft['recurrence_type'],
                            ) => {
                                setDraft((current) => ({
                                    ...current,
                                    recurrence_type: value,
                                    ends_local:
                                        value === 'once' &&
                                        current.recurrence_type !== 'once'
                                            ? recurringEnd(
                                                  current.starts_local,
                                                  current.ends_local,
                                              ).replace(' ', 'T')
                                            : value !== 'once' &&
                                                current.recurrence_type ===
                                                    'once'
                                              ? current.ends_local.slice(11)
                                              : current.ends_local,
                                }));
                            }}
                        >
                            {(
                                ['once', 'daily', 'weekly', 'monthly'] as const
                            ).map((type) => (
                                <RadioGroupItem key={type} value={type}>
                                    {t(
                                        type === 'once'
                                            ? 'Once'
                                            : type === 'daily'
                                              ? 'Daily'
                                              : type === 'weekly'
                                                ? 'Weekly'
                                                : 'Monthly',
                                    )}
                                </RadioGroupItem>
                            ))}
                        </RadioGroup>
                        {editing !== null && (
                            <p
                                id="maintenance-repeat-locked"
                                className="text-xs text-muted-foreground"
                            >
                                {t(
                                    "A window's repeat type cannot change; create a new window instead.",
                                )}
                            </p>
                        )}
                        <InputError
                            field="maintenance-repeat"
                            message={errors.recurrence_type}
                        />
                    </div>
                    {draft.recurrence_type !== 'once' && (
                        <div className="space-y-1">
                            <Label htmlFor="maintenance-interval">
                                {t(
                                    draft.recurrence_type === 'daily'
                                        ? 'Every N days'
                                        : draft.recurrence_type === 'weekly'
                                          ? 'Every N weeks'
                                          : 'Every {{count}} months',
                                    {
                                        count: Number(draft.interval),
                                        formattedCount:
                                            typeof draft.interval === 'number'
                                                ? format.number(draft.interval)
                                                : 'N',
                                    },
                                )}
                            </Label>
                            <NumberField
                                id="maintenance-interval"
                                min={1}
                                max={52}
                                invalid={!!errors.interval}
                                value={draft.interval}
                                describedBy="maintenance-interval-error"
                                onValueChange={(value) =>
                                    change('interval', value ?? 0)
                                }
                            />
                            <InputError
                                id="maintenance-interval-error"
                                message={errors.interval}
                            />
                        </div>
                    )}
                    {draft.recurrence_type === 'weekly' && (
                        <div className="space-y-1">
                            <Label id="maintenance-weekdays-label">
                                {t('Weekdays')}
                            </Label>
                            <WeekdayToggleGroup
                                id="maintenance-weekdays"
                                numbering="sunday-zero"
                                labelledBy="maintenance-weekdays-label"
                                describedBy="maintenance-weekdays-error"
                                invalid={
                                    !!(
                                        errors.days_of_week ??
                                        errors['days_of_week.0']
                                    )
                                }
                                value={draft.days_of_week}
                                onValueChange={(value) =>
                                    change('days_of_week', value)
                                }
                            />
                            <InputError
                                id="maintenance-weekdays-error"
                                message={
                                    errors.days_of_week ??
                                    errors['days_of_week.0']
                                }
                            />
                        </div>
                    )}
                    {draft.recurrence_type === 'monthly' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="maintenance-nth">
                                    {t('Nth weekday')}
                                </Label>
                                <Select
                                    value={String(draft.nth_weekday)}
                                    onValueChange={(value) =>
                                        change('nth_weekday', Number(value))
                                    }
                                >
                                    <SelectTrigger
                                        id="maintenance-nth"
                                        {...fieldErrorProps(
                                            'maintenance-nth',
                                            errors.nth_weekday,
                                        )}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="0">
                                            {t('Day of month')}
                                        </SelectItem>
                                        {[1, 2, 3, 4, 5].map((nth) => (
                                            <SelectItem
                                                key={nth}
                                                value={String(nth)}
                                            >
                                                {t('{{nth}} ordinal', {
                                                    nth: format.number(nth),
                                                    context:
                                                        new Intl.PluralRules(
                                                            i18n.language,
                                                            {
                                                                type: 'ordinal',
                                                            },
                                                        ).select(nth),
                                                })}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    field="maintenance-nth"
                                    message={errors.nth_weekday}
                                />
                            </div>
                            {draft.nth_weekday === 0 ? (
                                <div className="space-y-1">
                                    <Label htmlFor="maintenance-month-day">
                                        {t('Day of month')}
                                    </Label>
                                    <NumberField
                                        id="maintenance-month-day"
                                        min={1}
                                        max={31}
                                        invalid={!!errors.day_of_month}
                                        value={draft.day_of_month}
                                        describedBy="maintenance-month-day-error"
                                        onValueChange={(value) =>
                                            change('day_of_month', value ?? 0)
                                        }
                                    />
                                    <InputError
                                        id="maintenance-month-day-error"
                                        message={errors.day_of_month}
                                    />
                                </div>
                            ) : (
                                <div className="space-y-1">
                                    <Label htmlFor="maintenance-month-weekday">
                                        {t('Weekday')}
                                    </Label>
                                    <Select
                                        value={String(draft.day_of_week)}
                                        onValueChange={(value) =>
                                            change('day_of_week', Number(value))
                                        }
                                    >
                                        <SelectTrigger
                                            id="maintenance-month-weekday"
                                            {...fieldErrorProps(
                                                'maintenance-month-weekday',
                                                errors.day_of_week,
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'Sunday',
                                                'Monday',
                                                'Tuesday',
                                                'Wednesday',
                                                'Thursday',
                                                'Friday',
                                                'Saturday',
                                            ].map((day, index) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(index)}
                                                >
                                                    {t(day)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        field="maintenance-month-weekday"
                                        message={errors.day_of_week}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label htmlFor="maintenance-name">{t('Name')}</Label>
                        <Input
                            id="maintenance-name"
                            {...fieldErrorProps(
                                'maintenance-name',
                                errors.name,
                            )}
                            maxLength={255}
                            value={draft.name}
                            onChange={(event) =>
                                change('name', event.target.value)
                            }
                        />
                        <InputError
                            field="maintenance-name"
                            message={errors.name}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="maintenance-reason">
                            {t('Reason')}
                        </Label>
                        <Textarea
                            id="maintenance-reason"
                            {...fieldErrorProps(
                                'maintenance-reason',
                                errors.reason,
                            )}
                            maxLength={255}
                            value={draft.reason}
                            onChange={(event) =>
                                change('reason', event.target.value)
                            }
                        />
                        <InputError
                            field="maintenance-reason"
                            message={errors.reason}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <Label htmlFor="maintenance-mute">
                            {t('Mute alerts during the window')}
                        </Label>
                        <Switch
                            id="maintenance-mute"
                            {...fieldErrorProps(
                                'maintenance-mute',
                                errors.mute_alerts,
                            )}
                            checked={draft.mute_alerts}
                            onCheckedChange={(value) =>
                                change('mute_alerts', value)
                            }
                        />
                        <InputError
                            field="maintenance-mute"
                            message={errors.mute_alerts}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <Label htmlFor="maintenance-catch-up">
                            {t('Alert if still failing at the end')}
                        </Label>
                        <Switch
                            id="maintenance-catch-up"
                            {...fieldErrorProps(
                                'maintenance-catch-up',
                                errors.catch_up_at_end,
                            )}
                            checked={draft.catch_up_at_end}
                            onCheckedChange={(value) =>
                                change('catch_up_at_end', value)
                            }
                        />
                        <InputError
                            field="maintenance-catch-up"
                            message={errors.catch_up_at_end}
                        />
                    </div>
                    <InputError
                        message={
                            Object.keys(errors).length > 0
                                ? 'Check the highlighted fields and try again.'
                                : null
                        }
                    />
                </SheetForm>
            </Sheet>
            {/* Ending a running window is neutral; deleting a window, an occurrence or a series cannot be undone. */}
            <ConfirmDialog
                open={confirmation !== null}
                onOpenChange={(open) => !open && setConfirmation(null)}
                destructive={confirmation?.action !== 'end_now'}
                title={t(confirmationCopy.title)}
                description={t(confirmationCopy.description)}
                confirmLabel={t(confirmationCopy.label)}
                cancelLabel={t(
                    confirmation?.action === 'cancel_series'
                        ? 'Keep series'
                        : 'Keep window',
                )}
                onConfirm={confirmAction}
            />
        </PageContainer>
    );
}
