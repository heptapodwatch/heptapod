import { AbsoluteTime } from '@/components/absolute-time';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { StatusDot } from '@/components/monitoring';
import {
    FormSection,
    FormActions,
    FormSecondaryAction,
} from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { useFormat } from '@/hooks/use-format';
import { update } from '@/routes/settings/instance';
import { TextLink } from '@/components/text-link';

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
        mail_design: instancePage.mail_design,
    });
    const pendingErrorFocus = useRef(false);
    useEffect(() => {
        if (!form.processing && pendingErrorFocus.current) {
            document.getElementById('instance-name')?.focus();
            pendingErrorFocus.current = false;
        }
    }, [form.processing, form.errors]);
    const mailDesignField = fieldErrorProps(
        'instance-mail-design',
        form.errors.mail_design,
        'instance-mail-design-description',
    );
    const configured = (value: boolean) =>
        t(value ? 'Configured' : 'Not configured');
    const absent = t('Not configured');
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
                aria-busy={form.processing}
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(update.url(), {
                        preserveScroll: true,
                        onSuccess: ({ props }) => {
                            const page =
                                props.instancePage as Heptapod.Data.Ui.InstanceSettingsData;
                            const saved = {
                                name: page.name,
                                mail_design: page.mail_design,
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
                    title={t('Mail')}
                    description={t(
                        'Used for invites, reports and email channels.',
                    )}
                    detail={t(
                        'The mail transport is read-only; credentials are never shown.',
                    )}
                >
                    {instancePage.mail_log_only && (
                        <Alert tone="warning">
                            <AlertTitle>{t('Mails are not sent')}</AlertTitle>
                            <AlertDescription>
                                <p>
                                    {t(
                                        'The active mail transport only stores messages locally. Configure SMTP or an API transport to send mail.',
                                    )}
                                </p>
                                <p>
                                    {t(
                                        'Until then, generate a sign-in link on the server with:',
                                    )}
                                </p>
                                <code className="font-mono break-all text-foreground">
                                    php artisan heptapod:user:access-link
                                </code>
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="space-y-2">
                        <Label id="instance-mail-design-label">
                            {t('instance.mail_design.label')}
                        </Label>
                        <p
                            id="instance-mail-design-description"
                            className="text-xs text-muted-foreground"
                        >
                            {t('instance.mail_design.description')}{' '}
                            <InfoTooltip>
                                {t('instance.mail_design.detail')}
                            </InfoTooltip>
                        </p>
                        <RadioCards
                            labelledBy="instance-mail-design-label"
                            describedBy={mailDesignField['aria-describedby']}
                            invalid={mailDesignField['aria-invalid']}
                            value={form.data.mail_design}
                            disabled={form.processing}
                            onChange={(value) =>
                                form.setData('mail_design', value)
                            }
                            options={(['branded', 'plain'] as const).map(
                                (value) => ({
                                    value,
                                    label: t(`instance.mail_design.${value}`),
                                    description: t(
                                        `instance.mail_design.${value}_description`,
                                    ),
                                }),
                            )}
                        />
                        <InputError
                            field="instance-mail-design"
                            message={form.errors.mail_design}
                        />
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <ConfigValue
                            label={t('Mail transport')}
                            value={instancePage.mailer}
                            mono
                        />
                        <ConfigValue
                            label={t('From name')}
                            value={instancePage.from_name}
                        />
                        <ConfigValue
                            label={t('From address')}
                            value={instancePage.from_address}
                            mono
                        />
                        <ConfigValue
                            label={t('SMTP host')}
                            value={instancePage.smtp.host ?? absent}
                            mono
                        />
                        <ConfigValue
                            label={t('Port')}
                            value={instancePage.smtp.port ?? absent}
                            mono
                        />
                        <ConfigValue
                            label={t('SMTP scheme')}
                            value={
                                instancePage.smtp.scheme ??
                                t('Not explicitly configured')
                            }
                            mono
                        />
                        <ConfigValue
                            label={t('SMTP username')}
                            value={configured(
                                instancePage.smtp.username_configured,
                            )}
                        />
                        <ConfigValue
                            label={t('SMTP password')}
                            value={configured(
                                instancePage.smtp.password_configured,
                            )}
                        />
                    </dl>
                </FormSection>
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
                    detail={t('Use an external monitor.')}
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
                        <>
                            {form.processing ? (
                                t('Saving…')
                            ) : form.isDirty ? (
                                <>
                                    <StatusDot
                                        state="warning"
                                        label="Unsaved changes"
                                    />
                                    {t('Unsaved changes')}
                                </>
                            ) : (
                                t('No unsaved changes')
                            )}
                        </>
                    }
                >
                    <FormSecondaryAction
                        size="sm"
                        disabled={!form.isDirty || form.processing}
                        onClick={() => form.resetAndClearErrors()}
                    >
                        {t('Discard')}
                    </FormSecondaryAction>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={!form.isDirty || form.processing}
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
            </form>
        </>
    );
}
