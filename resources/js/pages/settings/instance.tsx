import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CheckboxField } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/hooks/use-format';
import { update } from '@/routes/settings/instance';
import { TextLink } from '@/components/text-link';
import {
    BrowserTimezoneHint,
    TimezoneCombobox,
} from '@/components/timezone-combobox';

function ConfigValue({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-1.5">
            <dt className="text-cell font-medium">{label}</dt>
            <dd
                className={`text-cell leading-relaxed break-words text-muted-foreground ${mono ? 'font-mono' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

export default function Instance({
    instancePage,
}: {
    instancePage: Heptapod.Data.Ui.InstanceSettingsData;
}) {
    const { t } = useTranslation();
    const { number } = useFormat();
    const form = useForm({
        name: instancePage.name,
        timezone: instancePage.timezone,
        servers_enabled: instancePage.servers_enabled,
    });

    const pendingErrorFocus = useRef(false);
    useEffect(() => {
        if (!form.processing && pendingErrorFocus.current) {
            document.getElementById('instance-name')?.focus();
            pendingErrorFocus.current = false;
        }
    }, [form.processing, form.errors]);
    const configured = (value: boolean) =>
        t(value ? 'Configured' : 'Not configured');
    const retention = instancePage.retention;
    const lastPing = instancePage.last_external_ping;

    return (
        <>
            <Head title={t('General')} />
            <PageHeader
                title={t('General')}
                description={t('pages.instance.description')}
            />
            <form
                noValidate
                id="instance-settings-form"
                aria-busy={form.processing}
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    // The environment's hard off: the switch is hidden and never sent.
                    form.transform(({ servers_enabled, ...data }) =>
                        instancePage.servers_enabled_by_server
                            ? { ...data, servers_enabled }
                            : data,
                    );
                    form.patch(update.url(), {
                        preserveScroll: true,
                        onSuccess: ({ props }) => {
                            const page =
                                props.instancePage as Heptapod.Data.Ui.InstanceSettingsData;
                            const saved = {
                                name: page.name,
                                timezone: page.timezone,
                                servers_enabled: page.servers_enabled,
                            };
                            form.setDefaults(saved);
                            form.setData(saved);
                        },
                        onError: () => {
                            pendingErrorFocus.current = true;
                        },
                    });
                }}
            >
                <FormSection
                    title={t('Identity')}
                    description={t(
                        'The instance name identifies this installation.',
                    )}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="instance-name">{t('Name')}</Label>
                            <Input
                                id="instance-name"
                                name="name"
                                required
                                value={form.data.name}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={!!form.errors.name}
                                aria-describedby={
                                    form.errors.name
                                        ? 'instance-name-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="instance-name-error"
                                message={form.errors.name}
                            />
                        </div>
                        <dl>
                            <ConfigValue
                                label={t('Address')}
                                value={instancePage.url}
                                mono
                            />
                        </dl>
                    </div>
                </FormSection>
                <FormSection
                    title={t('Timezone')}
                    description={t(
                        'Team notifications and everything without its own timezone use this one.',
                    )}
                    detail={t(
                        'Members, clients and channels can choose their own timezone. New members start with their browser timezone.',
                    )}
                >
                    <div className="space-y-4">
                        <BrowserTimezoneHint
                            onDefault={instancePage.timezone === 'UTC'}
                            current={instancePage.timezone}
                            draft={form.data.timezone}
                            timezones={instancePage.timezones}
                            onUse={(zone) => form.setData('timezone', zone)}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="instance-timezone">
                                    {t('Timezone')}
                                </Label>
                                <TimezoneCombobox
                                    id="instance-timezone"
                                    timezones={instancePage.timezones}
                                    value={form.data.timezone}
                                    onValueChange={(value) =>
                                        form.setData('timezone', value)
                                    }
                                    disabled={form.processing}
                                    invalid={!!form.errors.timezone}
                                    describedBy={
                                        form.errors.timezone
                                            ? 'instance-timezone-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="instance-timezone-error"
                                    message={form.errors.timezone}
                                />
                            </div>
                        </div>
                    </div>
                </FormSection>
                {instancePage.servers_enabled_by_server && (
                    <FormSection
                        title={t('instance.experimental.title')}
                        description={t('instance.experimental.description')}
                    >
                        <CheckboxField
                            checked={form.data.servers_enabled}
                            disabled={form.processing}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'servers_enabled',
                                    checked === true,
                                )
                            }
                            description={t('instance.servers.description')}
                        >
                            {t('instance.servers.label')}
                        </CheckboxField>
                    </FormSection>
                )}
                <FormSection
                    title={t('Defaults for new sites')}
                    description={t('Existing sites keep their settings.')}
                    detail={t(
                        'Defaults are defined by each check, not editable here.',
                    )}
                >
                    <dl>
                        <ConfigValue
                            label={t('Source')}
                            value={t(instancePage.defaults.source)}
                        />
                    </dl>
                </FormSection>
                <FormSection
                    title={t('Backup retention')}
                    description={t('Read-only backup cleanup policy.')}
                    detail={t(
                        'These values do not describe monitoring history retention.',
                    )}
                >
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <ConfigValue
                            label={t('All backups')}
                            value={t('{{count}} days', {
                                count: retention.keep_all_backups_for_days,
                                formattedCount: number(
                                    retention.keep_all_backups_for_days,
                                ),
                            })}
                        />
                        <ConfigValue
                            label={t('Daily backups')}
                            value={t('{{count}} days', {
                                count: retention.keep_daily_backups_for_days,
                                formattedCount: number(
                                    retention.keep_daily_backups_for_days,
                                ),
                            })}
                        />
                        <ConfigValue
                            label={t('Weekly backups')}
                            value={t('{{count}} weeks', {
                                count: retention.keep_weekly_backups_for_weeks,
                                formattedCount: number(
                                    retention.keep_weekly_backups_for_weeks,
                                ),
                            })}
                        />
                        <ConfigValue
                            label={t('Monthly backups')}
                            value={t('{{count}} months', {
                                count: retention.keep_monthly_backups_for_months,
                                formattedCount: number(
                                    retention.keep_monthly_backups_for_months,
                                ),
                            })}
                        />
                        <ConfigValue
                            label={t('Yearly backups')}
                            value={t('{{count}} years', {
                                count: retention.keep_yearly_backups_for_years,
                                formattedCount: number(
                                    retention.keep_yearly_backups_for_years,
                                ),
                            })}
                        />
                        <ConfigValue
                            label={t('Backup size limit')}
                            value={
                                retention.delete_oldest_backups_when_using_more_megabytes_than ===
                                null
                                    ? t('Unlimited')
                                    : t(':count MB', {
                                          count: number(
                                              retention.delete_oldest_backups_when_using_more_megabytes_than,
                                          ),
                                      })
                            }
                        />
                    </dl>
                </FormSection>
                <FormSection
                    title={t('Watch the watcher')}
                    description={t('If this server stops, it cannot tell you.')}
                    detail={t('Use a watchdog outside this server.')}
                >
                    <div className="space-y-4">
                        <dl className="space-y-4">
                            <ConfigValue
                                label={t('Outbound heartbeat')}
                                value={configured(
                                    instancePage.heartbeat_configured,
                                )}
                            />
                            <ConfigValue
                                label={t('Last successful heartbeat')}
                                value={
                                    lastPing === null ? (
                                        t('No successful heartbeat recorded')
                                    ) : (
                                        <AbsoluteTime value={lastPing} />
                                    )
                                }
                            />
                        </dl>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Configure INSTANCE_HEARTBEAT_URL in the environment. The destination URL is not exposed here.',
                            )}
                        </p>
                        <div className="space-y-1.5">
                            <SectionHeading level={3}>
                                {t('Health URL for polling')}
                            </SectionHeading>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Laravel application health endpoint. This is not a scheduler or worker status check.',
                                )}
                            </p>
                            <TextLink
                                external
                                url
                                className="block rounded-lg border bg-card px-3.5 py-3 text-xs"
                                href={instancePage.health_url}
                            >
                                {instancePage.health_url}
                            </TextLink>
                        </div>
                    </div>
                </FormSection>
                <FormActions
                    status={
                        <FormSaveStatus
                            dirty={form.isDirty}
                            processing={form.processing}
                        />
                    }
                >
                    <FormSecondaryAction
                        disabled={!form.isDirty || form.processing}
                        onClick={() => form.resetAndClearErrors()}
                    >
                        {t('Discard')}
                    </FormSecondaryAction>
                    <Button
                        type="submit"
                        form="instance-settings-form"
                        disabled={!form.isDirty || form.processing}
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
            </form>
        </>
    );
}
