import { InfoTooltip } from '@/components/info-tooltip';
import { TooltipHint } from '@/components/truncated-text';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { SiteAlertSensitivity } from '@/components/alert-sensitivity-form';
import { SiteAvatar } from '@/components/site-avatar';
import { useLiveReload } from '@/hooks/use-live';
import { refresh as refreshIcon } from '@/routes/sites/icon';
import { SiteTabs } from '@/components/site-tabs';
import { PageContainer } from '@/components/page-container';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useSiteUrlProbe } from '@/hooks/use-site-url-probe';
import { useTranslation } from 'react-i18next';
import { SiteHeader } from '@/components/site-header';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import {
    SiteNameField,
    SiteClientField,
    SiteUrlField,
    SiteTagsField,
    SiteCheckField,
} from '@/components/site-fields';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { checkSettingsSummary } from '@/lib/check-setting-facts';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/hooks/use-format';
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

export default function SiteSettings({
    site,
    client,
    clients,
    resume_at,
    check_count,
    incident_count,
    result_count,
    run_all_checks,
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
}: Heptapod.Data.Ui.SiteSettingsPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const checkSettings = new Map(
        check_settings.map((check) => [check.check_id, check]),
    );
    const iconForm = useForm<{ icon?: string }>({});
    const iconPending = icon_manual_result?.key === 'icons.queued';
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
    });
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
        form.transform(({ tags, ...data }) => ({
            ...data,
            tags: [
                ...new Set(
                    tags
                        .split(',')
                        .map((tag) => tag.trim())
                        .filter(Boolean),
                ),
            ],
        }));
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
                };
                form.setDefaults(values);
                form.setData(values);
            },
        });
    };
    return (
        <>
            <Head title={t('Site settings')} />
            <PageContainer>
                <SiteHeader
                    showTrail
                    site={site}
                    client={client}
                    canManage
                    runAllChecks={run_all_checks}
                    maintenance={maintenance}
                    resumeAt={resume_at}
                />
                <SiteTabs
                    siteId={site.id}
                    checks={site.checks.filter((check) => check.enabled)}
                    value="settings"
                    canManage
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
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SiteNameField
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
                                <SiteClientField
                                    clients={clientOptions}
                                    value={form.data.client_id}
                                    onValueChange={(value) =>
                                        form.setData('client_id', value)
                                    }
                                    onCreateClient={setCreateClientName}
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
                                    value={form.data.tags}
                                    onChange={(event) =>
                                        form.setData('tags', event.target.value)
                                    }
                                    error={form.errors.tags}
                                />
                            </div>
                        </div>
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
                            {site.checks.map((check) => {
                                const settings = checkSettings.get(check.id);
                                return (
                                    <SiteCheckField
                                        key={check.id}
                                        id={`settings-check-${check.id}`}
                                        label={t(check.label_key)}
                                        summary={
                                            settings &&
                                            checkSettingsSummary(
                                                settings,
                                                t,
                                                format,
                                            ).join(' · ')
                                        }
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
                                                            check: t(
                                                                check.label_key,
                                                            ),
                                                        },
                                                    )}
                                                />
                                            )
                                        }
                                        control={
                                            <Switch
                                                id={`settings-check-${check.id}`}
                                                aria-label={t(check.label_key)}
                                                aria-describedby={
                                                    settings
                                                        ? `settings-check-${check.id}-summary`
                                                        : undefined
                                                }
                                                checked={form.data.checks.includes(
                                                    check.key,
                                                )}
                                                onCheckedChange={(selected) =>
                                                    form.setData(
                                                        'checks',
                                                        selected
                                                            ? [
                                                                  ...form.data
                                                                      .checks,
                                                                  check.key,
                                                              ]
                                                            : form.data.checks.filter(
                                                                  (key) =>
                                                                      key !==
                                                                      check.key,
                                                              ),
                                                    )
                                                }
                                            />
                                        }
                                    />
                                );
                            })}
                            {!site.checks.length && (
                                <p className="p-3 text-sm text-muted-foreground">
                                    {t('No checks configured')}
                                </p>
                            )}
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
                        size="sm"
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
                        size="sm"
                        disabled={!form.isDirty || form.processing}
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
                {site.checks.some((check) => check.key === 'uptime') && (
                    <SiteAlertSensitivity
                        siteId={site.id}
                        saved={alert_sensitivity}
                        team={team_alert_sensitivity}
                        presets={alert_sensitivity_presets}
                        bounds={alert_sensitivity_bounds}
                    />
                )}
                <FormSection
                    title={t('icons.title')}
                    description={t('icons.description')}
                    detail={t('icons.description_detail')}
                >
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
                            size="sm"
                            disabled={
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
                    <p className="text-sm" role="status">
                        {icon_manual_result &&
                            (iconRefreshOutcomes.has(icon_manual_result.key)
                                ? t(
                                      icon_manual_result.key,
                                      icon_manual_result.params,
                                  )
                                : `${t('icons.refresh_failed', {
                                      reason: t(
                                          icon_manual_result.key,
                                          icon_manual_result.params,
                                      ),
                                  })} ${t(
                                      site.identity.icon_url
                                          ? 'icons.showing_previous'
                                          : 'icons.showing_letters',
                                  )}`)}
                    </p>
                    <InputError message={iconForm.errors.icon} />
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
            </PageContainer>
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
