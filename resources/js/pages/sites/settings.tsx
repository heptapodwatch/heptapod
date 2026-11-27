import { ActionNoteSlot } from '@/components/action-note-slot';
import { InfoTooltip } from '@/components/info-tooltip';
import { TooltipHint } from '@/components/truncated-text';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { ClientField } from '@/components/client-field';
import { SiteServerField } from '@/components/server-manage';
import { SiteAlertSensitivity } from '@/components/alert-sensitivity-form';
import { SiteAvatar } from '@/components/site-avatar';
import { useLiveReload } from '@/hooks/use-live';
import { refresh as refreshIcon } from '@/routes/sites/icon';
import { SiteSectionsLayout } from '@/layouts/site-sections-layout';
import { PageHeader } from '@/components/page-header';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    categories,
    NotificationExplanation,
} from '@/components/notification-explanation';
import { useId, useRef, useState } from 'react';
import { useSiteUrlProbe } from '@/hooks/use-site-url-probe';
import { useTranslation } from 'react-i18next';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import {
    SiteNameField,
    SiteUrlField,
    SiteTagsField,
    SiteCheckField,
} from '@/components/site-fields';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { checkSettingsSummary } from '@/lib/check-setting-facts';
import { checkNeedsSetup, siteCheckOrder } from '@/components/site-checks-list';
import type { FieldValue } from '@/types/monitoring';
import { eligibleSiteCheck } from '@/lib/site-create-draft';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/hooks/use-format';
import { ReplicatorField } from '@/components/replicator-field';
import {
    index as sitesIndex,
    update as siteUpdate,
    destroy as siteDelete,
} from '@/routes/sites';

// Manual results that are not fetch failures; every other key is a failure reason for the last refresh.
const iconRefreshOutcomes = new Set([
    'icons.refreshed',
    'icons.queued',
    'icons.unavailable',
    'icons.throttled',
]);

/** The saved links as editable rows; an empty label is the empty string, which the server stores as none. */
function repositoryRows(repositories: Heptapod.Data.Ui.SiteRepositoryData[]) {
    return repositories.map((repository) => ({
        url: repository.url,
        label: repository.label ?? '',
    }));
}

type SiteServerChoices = Heptapod.Addons.Servers.Data.SiteServerChoicesData;

const serverChoice = (server: SiteServerChoices | null) =>
    server?.server_id == null ? 'none' : String(server.server_id);

export default function SiteSettings({
    site,
    client,
    clients,
    check_count,
    incident_count,
    result_count,
    maintenance,
    icon_fetched_at,
    icon_manual_result,
    start_redirect_target_url,
    start_redirect_summary_key,
    alert_sensitivity,
    team_alert_sensitivity,
    alert_sensitivity_presets,
    alert_sensitivity_bounds,
    timezones,
    check_settings,
    check_options,
    repositories,
    server,
}: Heptapod.Data.Ui.SiteSettingsPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const archivedClient = client.archived_at !== null;
    const showSensitivity =
        !archivedClient && site.checks.some((check) => check.key === 'uptime');
    // "Details" on the overview and the check tabs opens #alerts with ?check=&category=.
    const query = new URL(usePage().url, 'http://localhost').searchParams;
    const enabledChecks = site.checks.filter((check) => check.enabled);
    const explainedCheck =
        enabledChecks.find(
            (check) => String(check.id) === query.get('check'),
        ) ??
        enabledChecks.find((check) => check.key === 'uptime') ??
        enabledChecks[0];
    const scrolledToAlerts = useRef(false);
    const explainedCategory =
        categories.find((category) => category === query.get('category')) ??
        (explainedCheck?.key === 'uptime' ? 'outage' : 'failure');
    const checkSettings = new Map(
        check_settings.map((check) => [check.check_id, check]),
    );
    // Every installed type in the wizard's order with the site's check of that type; a type without one is created
    // on Save when turned on. A stored check of a type that is no longer installed keeps its row.
    const checkRows = [
        ...check_options.map((option) => ({
            key: option.key,
            label_key: option.label_key,
            option,
            check: site.checks.find((check) => check.key === option.key),
        })),
        ...site.checks
            .filter(
                (check) =>
                    !check_options.some((option) => option.key === check.key),
            )
            .map((check) => ({
                key: check.key,
                label_key: check.label_key,
                option: undefined,
                check,
            })),
    ].sort(
        (left, right) =>
            siteCheckOrder.indexOf(left.key) -
            siteCheckOrder.indexOf(right.key),
    );
    const [setupCheck, setSetupCheck] =
        useState<Heptapod.Data.Ui.CheckOptionData | null>(null);
    const iconForm = useForm<{ icon?: string }>({});
    const iconPending = icon_manual_result?.key === 'icons.queued';
    const iconNoteId = useId();
    const iconStandingNote = icon_manual_result ? (
        <p role="status" className="text-foreground">
            {iconRefreshOutcomes.has(icon_manual_result.key)
                ? t(icon_manual_result.key, icon_manual_result.params)
                : `${t('icons.refresh_failed', {
                      reason: t(
                          icon_manual_result.key,
                          icon_manual_result.params,
                      ),
                  })} ${t(
                      site.identity.icon_url
                          ? 'icons.showing_previous'
                          : 'icons.showing_letters',
                  )}`}
        </p>
    ) : null;
    const iconHasNote = Boolean(iconForm.errors.icon || iconStandingNote);
    const canRefreshIcon = !archivedClient && site.identity.state !== 'paused';
    const reloadPage = useLiveReload(
        ['state.changed'],
        [
            'site',
            'start_redirect_target_url',
            'start_redirect_summary_key',
            'icon_fetched_at',
            'icon_manual_result',
            'alert_sensitivity',
            'team_alert_sensitivity',
            'check_settings',
        ],
        (signal) => signal.ids.site === site.id,
        true,
        {
            missingResource: {
                url: sitesIndex.url(),
                message: t('Site deleted'),
            },
        },
    );
    const [createdClients, setCreatedClients] = useState<
        Heptapod.Data.Ui.ClientData[]
    >([]);
    const [createClientName, setCreateClientName] = useState<string | null>(
        null,
    );
    const clientOptions = [
        ...clients,
        ...createdClients.filter(
            (created) => !clients.some((option) => option.id === created.id),
        ),
    ];
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [impactPending, setImpactPending] = useState(false);
    const [impactError, setImpactError] = useState(false);
    const resultCount = typeof result_count === 'number' ? result_count : null;
    const [confirmation, setConfirmation] = useState('');
    const form = useForm({
        client_id: String(site.client_id),
        friendly_name: site.identity.name,
        url: site.identity.url,
        tags: site.tags.join(', '),
        checks: site.checks
            .filter((check) => check.enabled)
            .map((check) => check.key),
        // Draft settings of types without a check, keyed `<type>.<field>` as in the add-site wizard.
        settings: {} as Record<string, FieldValue>,
        repositories: repositoryRows(repositories),
        // The servers addon's link, a draft like every other field; 'none' is "Not linked".
        server_id: serverChoice(server),
    });
    // The add-site wizard's reason why a type cannot run for this site, against the address being edited. Scheduled
    // tasks are only created through the API, so the reason applies to a type the site has no check of.
    const unavailableReason = (row: (typeof checkRows)[number]) =>
        !row.option ||
        eligibleSiteCheck(row.option, form.data.url) ||
        (row.check && row.option.key === 'cron')
            ? undefined
            : row.option.unavailable_reason
              ? t(row.option.unavailable_reason)
              : row.option.key === 'cron'
                ? t(
                      'Scheduled tasks are added through the API. Create a token in Settings → API tokens.',
                  )
                : t('add_site.unavailable.https_hint');
    // A saved-on check stays switchable so it can be turned off; every other row with a reason cannot be turned on.
    const blocked = (row: (typeof checkRows)[number]) =>
        !row.check?.enabled && unavailableReason(row) !== undefined;
    const newChecks = checkRows.filter(
        (row) =>
            !row.check && !blocked(row) && form.data.checks.includes(row.key),
    );
    const { probe, probeState, probeError, runProbe, clearProbe } =
        useSiteUrlProbe(form.data.url, false, site.id);
    const loadDeletionImpact = () => {
        setImpactPending(true);
        setImpactError(false);
        reloadPage({
            only: ['result_count'],
            onError: () => setImpactError(true),
            onHttpException: (response) => {
                console.error(
                    'Site deletion impact request failed:',
                    response.status,
                );
                setImpactError(true);
            },
            onNetworkError: (error) => {
                console.error(error);
                setImpactError(true);
            },
            onFinish: () => setImpactPending(false),
        });
    };
    const save = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform(({ tags, settings, server_id, ...data }) =>
            archivedClient
                ? { client_id: data.client_id }
                : {
                      ...data,
                      // Only those who may see servers have the field; the server refuses it from anyone else.
                      ...(server
                          ? {
                                server_id:
                                    server_id === 'none'
                                        ? null
                                        : Number(server_id),
                            }
                          : {}),
                      checks: data.checks.filter(
                          (key) =>
                              !checkRows.some(
                                  (row) => row.key === key && blocked(row),
                              ),
                      ),
                      ...Object.fromEntries(
                          newChecks.map(({ key }) => [
                              key + '_check_settings',
                              Object.fromEntries(
                                  Object.entries(settings)
                                      .filter(([name]) =>
                                          name.startsWith(key + '.'),
                                      )
                                      .map(([name, value]) => [
                                          name.slice(key.length + 1),
                                          value,
                                      ]),
                              ),
                          ]),
                      ),
                      tags: [
                          ...new Set(
                              tags
                                  .split(',')
                                  .map((tag) => tag.trim())
                                  .filter(Boolean),
                          ),
                      ],
                  },
        );
        form.patch(siteUpdate.url(site.id), {
            preserveScroll: true,
            onSuccess: ({ props }) => {
                const saved = props.site as Heptapod.Data.Ui.SiteRowData;
                const values = {
                    client_id: String(saved.client_id),
                    friendly_name: saved.identity.name,
                    url: saved.identity.url,
                    tags: saved.tags.join(', '),
                    checks: saved.checks
                        .filter((check) => check.enabled)
                        .map((check) => check.key),
                    settings: {},
                    repositories: repositoryRows(
                        props.repositories as Heptapod.Data.Ui.SiteRepositoryData[],
                    ),
                    server_id: serverChoice(
                        props.server as SiteServerChoices | null,
                    ),
                };
                form.setDefaults(values);
                form.setData(values);
            },
            onError: (errors) => {
                const refused = newChecks.find(({ key }) =>
                    Object.keys(errors).some((name) =>
                        name.startsWith(`checks.${key}.`),
                    ),
                );
                if (refused?.option) setSetupCheck(refused.option);
            },
        });
    };
    return (
        <>
            <Head title={t('Site settings')} />
            <SiteSectionsLayout
                site={site}
                client={client}
                checks={enabledChecks}
                value="settings"
                canOpenSettings
                maintenance={maintenance}
            >
                <PageHeader
                    title={t('Site settings')}
                    description={t('site_sections.settings_description')}
                />
                <form
                    noValidate
                    id="site-settings-form"
                    onSubmit={save}
                    aria-busy={form.processing}
                    className="space-y-4"
                >
                    <FormSection
                        title={t('General')}
                        description={t(
                            'How this site appears in your portfolio.',
                        )}
                    >
                        {archivedClient && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'This client is archived; move the site to an active client to manage it, then resume it explicitly.',
                                )}
                            </p>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SiteNameField
                                readOnly={archivedClient}
                                value={form.data.friendly_name}
                                onChange={(event) =>
                                    form.setData(
                                        'friendly_name',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.friendly_name}
                            />
                            <div className="space-y-2">
                                <ClientField
                                    clients={clientOptions}
                                    value={form.data.client_id}
                                    placeholder={
                                        archivedClient ? client.name : undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData('client_id', value)
                                    }
                                    onCreateClient={
                                        archivedClient
                                            ? undefined
                                            : setCreateClientName
                                    }
                                    disabled={form.processing}
                                    error={form.errors.client_id}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('sites.client_move_help')}{' '}
                                    <InfoTooltip>
                                        {t('sites.client_move_help.detail')}
                                    </InfoTooltip>
                                </p>
                            </div>
                            <div className="sm:col-span-2">
                                <SiteUrlField
                                    readOnly={archivedClient}
                                    type="text"
                                    inputMode="url"
                                    required
                                    value={form.data.url}
                                    onChange={(event) => {
                                        clearProbe();
                                        form.setData('url', event.target.value);
                                    }}
                                    onBlur={() => {
                                        if (form.data.url !== site.identity.url)
                                            void runProbe().then((result) => {
                                                if (result?.normalised_url)
                                                    form.setData(
                                                        'url',
                                                        result.normalised_url,
                                                    );
                                            });
                                    }}
                                    probe={probe}
                                    probeState={probeState}
                                    probeError={probeError}
                                    onUseAddress={(url) => {
                                        clearProbe();
                                        form.setData('url', url);
                                    }}
                                    error={form.errors.url}
                                />
                                {start_redirect_target_url &&
                                    start_redirect_summary_key && (
                                        <p className="mt-2 text-sm break-words text-muted-foreground">
                                            {t(start_redirect_summary_key, {
                                                start_redirect_target_url,
                                            })}
                                        </p>
                                    )}
                            </div>
                            <div className="sm:col-span-2">
                                <SiteTagsField
                                    readOnly={archivedClient}
                                    value={form.data.tags}
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                    error={form.errors.tags}
                                />
                            </div>
                        </div>
                    </FormSection>
                    {server && !archivedClient && (
                        <FormSection
                            title={t('servers.site.section')}
                            description={t('servers.site.section_description')}
                        >
                            <SiteServerField
                                choices={server}
                                value={form.data.server_id}
                                onValueChange={(value) =>
                                    form.setData('server_id', value)
                                }
                                disabled={form.processing}
                                error={form.errors.server_id}
                            />
                        </FormSection>
                    )}
                    <FormSection
                        title={t('repositories.title')}
                        description={t('repositories.description')}
                    >
                        <ReplicatorField
                            id="site-repositories"
                            label={t('repositories.title')}
                            columns={[
                                {
                                    key: 'url',
                                    label: t('repositories.url'),
                                    placeholder: 'https://github.com/org/repo',
                                    mono: true,
                                },
                                {
                                    key: 'label',
                                    label: t('repositories.label'),
                                    placeholder: t(
                                        'repositories.label_placeholder',
                                    ),
                                    narrow: true,
                                },
                            ]}
                            rows={form.data.repositories}
                            onRowsChange={(rows) =>
                                form.setData('repositories', rows)
                            }
                            max={10}
                            addLabel={t('repositories.add')}
                            rowName={(index) =>
                                t('repositories.row', { number: index + 1 })
                            }
                            empty={t('repositories.empty')}
                            error={(index, key) =>
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                )[`repositories.${index}.${key}`]
                            }
                            disabled={archivedClient || form.processing}
                        />
                    </FormSection>
                    <FormSection
                        title={t('Checks')}
                        description={t('Turn checks on or off.')}
                        detail={t('checks.settings_section.detail')}
                    >
                        <div
                            role="group"
                            id="settings-checks"
                            aria-label={t('Checks')}
                            {...fieldErrorProps(
                                'settings-checks',
                                form.errors.checks,
                            )}
                            className="divide-y rounded-lg border"
                        >
                            {checkRows.map((row) => {
                                const { key, label_key, check, option } = row;
                                const settings =
                                    check && checkSettings.get(check.id);
                                const reason = unavailableReason(row);
                                const id = `settings-check-${check?.id ?? key}`;
                                const summary =
                                    reason ??
                                    (settings &&
                                        [
                                            ...(settings.attention_reason
                                                ? [t('Needs attention')]
                                                : []),
                                            ...checkSettingsSummary(
                                                settings,
                                                t,
                                                format,
                                            ),
                                        ].join(' · '));
                                return (
                                    <SiteCheckField
                                        key={key}
                                        id={id}
                                        label={t(label_key)}
                                        summary={summary}
                                        action={
                                            settings?.can_update && (
                                                <CheckSettingsSheet
                                                    check={settings}
                                                    discoveredUrls={null}
                                                    reloadOnly={[
                                                        'check_settings',
                                                    ]}
                                                    triggerLabel={t(
                                                        'checks.settings_section.configure',
                                                        {
                                                            check: t(label_key),
                                                        },
                                                    )}
                                                />
                                            )
                                        }
                                        control={
                                            <Switch
                                                id={id}
                                                aria-label={t(label_key)}
                                                aria-describedby={
                                                    summary
                                                        ? `${id}-summary`
                                                        : undefined
                                                }
                                                disabled={
                                                    archivedClient ||
                                                    blocked(row)
                                                }
                                                checked={
                                                    !blocked(row) &&
                                                    form.data.checks.includes(
                                                        key,
                                                    )
                                                }
                                                onCheckedChange={(selected) => {
                                                    form.setData(
                                                        'checks',
                                                        selected
                                                            ? [
                                                                  ...form.data
                                                                      .checks,
                                                                  key,
                                                              ]
                                                            : form.data.checks.filter(
                                                                  (
                                                                      selectedKey,
                                                                  ) =>
                                                                      selectedKey !==
                                                                      key,
                                                              ),
                                                    );
                                                    // As in the wizard, a new check is never saved without its required settings.
                                                    if (
                                                        selected &&
                                                        !check &&
                                                        option &&
                                                        checkNeedsSetup(
                                                            option,
                                                            form.data.settings,
                                                        )
                                                    )
                                                        setSetupCheck(option);
                                                }}
                                            />
                                        }
                                    />
                                );
                            })}
                        </div>
                        <InputError
                            field="settings-checks"
                            message={form.errors.checks}
                        />
                    </FormSection>
                </form>
                <FormActions
                    data-site-savebar
                    status={
                        <FormSaveStatus
                            dirty={form.isDirty}
                            processing={form.processing}
                        />
                    }
                >
                    <FormSecondaryAction
                        disabled={!form.isDirty || form.processing}
                        onClick={() => {
                            clearProbe();
                            form.resetAndClearErrors();
                        }}
                    >
                        {t('Discard')}
                    </FormSecondaryAction>
                    <Button
                        id="site-settings-save"
                        type="submit"
                        form="site-settings-form"
                        disabled={!form.isDirty || form.processing}
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
                {(showSensitivity || explainedCheck) && (
                    <div id="alerts" className="scroll-mt-4">
                        <FormSection
                            title={t('alert_sensitivity.alerts')}
                            description={t('site_settings.alerts_description')}
                        >
                            {showSensitivity && (
                                <SiteAlertSensitivity
                                    siteId={site.id}
                                    saved={alert_sensitivity}
                                    team={team_alert_sensitivity}
                                    presets={alert_sensitivity_presets}
                                    bounds={alert_sensitivity_bounds}
                                />
                            )}
                            {explainedCheck && (
                                <NotificationExplanation
                                    checkId={explainedCheck.id}
                                    defaultCategory={explainedCategory}
                                    clientId={site.client_id}
                                    checks={enabledChecks.map((check) => ({
                                        id: check.id,
                                        label: t(check.label_key),
                                    }))}
                                    onExplained={() => {
                                        // Inertia scrolls to #alerts before the explanation has loaded and lengthened the page.
                                        if (
                                            !scrolledToAlerts.current &&
                                            window.location.hash === '#alerts'
                                        )
                                            document
                                                .getElementById('alerts')
                                                ?.scrollIntoView();
                                        scrolledToAlerts.current = true;
                                    }}
                                />
                            )}
                        </FormSection>
                    </div>
                )}
                <FormSection
                    title={t('icons.title')}
                    description={t('icons.description')}
                    detail={t('icons.description_detail')}
                >
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-3">
                            <SiteAvatar
                                name={site.identity.name}
                                iconUrl={site.identity.icon_url}
                                iconBacking={site.identity.icon_backing}
                            />
                            <div className="min-w-0 flex-1 text-sm text-muted-foreground">
                                <p>
                                    {site.identity.icon_url
                                        ? t('icons.current')
                                        : t('icons.letters')}
                                </p>
                                <TooltipHint
                                    content={
                                        icon_fetched_at
                                            ? format.exact(icon_fetched_at)
                                            : undefined
                                    }
                                >
                                    <p className="font-mono tabular-nums">
                                        {icon_fetched_at
                                            ? t('icons.fetched', {
                                                  date: format.timestamp(
                                                      icon_fetched_at,
                                                  ),
                                              })
                                            : t('icons.never')}
                                    </p>
                                </TooltipHint>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                aria-describedby={
                                    iconHasNote ? iconNoteId : undefined
                                }
                                disabled={
                                    archivedClient ||
                                    iconForm.processing ||
                                    iconPending ||
                                    site.identity.state === 'paused'
                                }
                                onClick={() =>
                                    iconForm.post(refreshIcon.url(site.id), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {t('icons.refresh')}
                            </Button>
                        </div>
                        {(canRefreshIcon || iconHasNote) && (
                            <ActionNoteSlot id={iconNoteId}>
                                {iconForm.errors.icon ? (
                                    <InputError
                                        message={iconForm.errors.icon}
                                    />
                                ) : (
                                    iconStandingNote
                                )}
                            </ActionNoteSlot>
                        )}
                    </div>
                </FormSection>
                <FormSection
                    title={t('Delete')}
                    description={t(
                        'Deleting removes this site, its checks, runs and incidents.',
                    )}
                    detail={t('Notification history is kept.')}
                >
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="destructive"
                            disabled={archivedClient}
                            onClick={() => {
                                setDeleteOpen(true);
                                loadDeletionImpact();
                            }}
                        >
                            {t('Delete site')}
                        </Button>
                        <ConfirmDialog
                            open={deleteOpen}
                            onOpenChange={(open) => {
                                setDeleteOpen(open);
                                if (!open) setConfirmation('');
                            }}
                            destructive
                            title={t('Delete {{name}}?', {
                                name: site.identity.name,
                            })}
                            description={
                                impactError
                                    ? t('Stored results could not be counted.')
                                    : impactPending || resultCount === null
                                      ? t('Counting stored results…')
                                      : t(
                                            'Deleting removes {{checks}}, {{incidents}} and {{results}}.',
                                            {
                                                checks: t('{{count}} checks', {
                                                    count: check_count,
                                                    formattedCount:
                                                        format.number(
                                                            check_count,
                                                        ),
                                                }),
                                                incidents: t(
                                                    '{{count}} incidents',
                                                    {
                                                        count: incident_count,
                                                        formattedCount:
                                                            format.number(
                                                                incident_count,
                                                            ),
                                                    },
                                                ),
                                                results: t(
                                                    '{{count}} stored results',
                                                    {
                                                        count: resultCount,
                                                        formattedCount:
                                                            format.number(
                                                                resultCount,
                                                            ),
                                                    },
                                                ),
                                            },
                                        )
                            }
                            confirmLabel={t('Delete site')}
                            confirmDisabled={
                                impactPending ||
                                impactError ||
                                resultCount === null ||
                                confirmation !== site.identity.name
                            }
                            onConfirm={() =>
                                confirmVisit((callbacks) =>
                                    router.delete(siteDelete.url(site.id), {
                                        data: { name: confirmation },
                                        ...callbacks,
                                    }),
                                )
                            }
                        >
                            {impactError && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="justify-self-start"
                                    onClick={loadDeletionImpact}
                                >
                                    {t('Retry')}
                                </Button>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="confirm-site-name">
                                    {t('Type the site name to confirm')}
                                </Label>
                                <Input
                                    id="confirm-site-name"
                                    value={confirmation}
                                    onChange={(event) =>
                                        setConfirmation(event.target.value)
                                    }
                                    placeholder={site.identity.name}
                                />
                            </div>
                        </ConfirmDialog>
                    </div>
                </FormSection>
            </SiteSectionsLayout>
            {setupCheck && (
                <CheckSettingsSheet
                    check={setupCheck}
                    settings={form.data.settings}
                    errors={Object.fromEntries(
                        Object.entries(form.errors)
                            .filter(([name]) =>
                                name.startsWith(`checks.${setupCheck.key}.`),
                            )
                            .map(([name, message]) => [
                                name.slice('checks.'.length),
                                message,
                            ]),
                    )}
                    onClose={() => {
                        // Cancelled without its required settings, the new check stays off.
                        if (checkNeedsSetup(setupCheck, form.data.settings))
                            form.setData(
                                'checks',
                                form.data.checks.filter(
                                    (key) => key !== setupCheck.key,
                                ),
                            );
                        setSetupCheck(null);
                    }}
                    onApply={(settings) => {
                        form.setData('settings', settings);
                        form.clearErrors();
                        setSetupCheck(null);
                    }}
                />
            )}
            {createClientName !== null && (
                <ClientCreateSheet
                    initialName={createClientName}
                    timezones={timezones}
                    onClose={() => setCreateClientName(null)}
                    onCreated={(created) => {
                        setCreatedClients((current) => [...current, created]);
                        form.setData('client_id', String(created.id));
                        setCreateClientName(null);
                    }}
                />
            )}
        </>
    );
}
