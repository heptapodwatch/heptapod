import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import { InfoTooltip } from '@/components/info-tooltip';
import InputError, { fieldErrorProps } from '@/components/input-error';
import {
    BrandingFields,
    BrandingPreview,
    ExploreSwitcher,
    exploreState,
    useBrandingDraft,
} from '@/components/mail-branding-explore';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { update } from '@/routes/settings/email';
import { Head, useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

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

export default function Email({
    instancePage,
}: {
    instancePage: Heptapod.Data.Ui.InstanceSettingsData;
}) {
    const { t } = useTranslation();
    const form = useForm({ mail_design: instancePage.mail_design });
    // Exploration (phase 1): the appearance fields are a local draft; Save marks them saved without storing them.
    const [state] = useState(exploreState);
    const branding = useBrandingDraft(state);
    const dirty = form.isDirty || branding.dirty;
    const mailDesignField = fieldErrorProps(
        'email-mail-design',
        form.errors.mail_design,
        'email-mail-design-description',
    );
    const configured = (value: boolean) =>
        t(value ? 'Configured' : 'Not configured');
    const absent = t('Not configured');
    const previewProps = {
        draft: branding.draft,
        design: form.data.mail_design,
        fromName: instancePage.from_name,
        fromAddress: instancePage.from_address,
    };

    return (
        <>
            <Head title={t('settings.email.title')} />
            <PageHeader
                title={t('settings.email.title')}
                description={t('settings.email.description')}
                notices={
                    <>
                        {instancePage.mail_log_only && (
                            <Alert tone="warning">
                                <AlertTitle>
                                    {t('Mails are not sent')}
                                </AlertTitle>
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
                        <ExploreSwitcher state={state} />
                    </>
                }
            />
            <form
                noValidate
                id="email-settings-form"
                aria-busy={form.processing}
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    branding.markSaved();
                    if (!form.isDirty) return;
                    form.patch(update.url(), {
                        preserveScroll: true,
                        onSuccess: ({ props }) => {
                            const page =
                                props.instancePage as Heptapod.Data.Ui.InstanceSettingsData;
                            form.setDefaults({ mail_design: page.mail_design });
                            form.setData({ mail_design: page.mail_design });
                        },
                    });
                }}
            >
                <FormSection
                    title={t('mail_branding.section.title')}
                    description={t('mail_branding.section.description')}
                    detail={t('mail_branding.section.detail')}
                    preview={<BrandingPreview {...previewProps} />}
                >
                    <div className="space-y-2">
                        <Label id="email-mail-design-label">
                            {t('instance.mail_design.label')}
                        </Label>
                        <p
                            id="email-mail-design-description"
                            className="text-xs text-muted-foreground"
                        >
                            {t('instance.mail_design.description')}{' '}
                            <InfoTooltip>
                                {t('instance.mail_design.detail')}
                            </InfoTooltip>
                        </p>
                        <RadioCards
                            labelledBy="email-mail-design-label"
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
                            field="email-mail-design"
                            message={form.errors.mail_design}
                        />
                    </div>
                    <BrandingFields
                        {...previewProps}
                        onChange={branding.setDraft}
                        plain={form.data.mail_design === 'plain'}
                    />
                </FormSection>
                <FormSection
                    title={t('settings.email.delivery.title')}
                    description={t('settings.email.delivery.description')}
                    detail={t(
                        'The mail transport is read-only; credentials are never shown.',
                    )}
                >
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <ConfigValue
                            label={t('Mail transport')}
                            value={instancePage.mailer}
                            mono
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
                <FormActions
                    status={
                        <FormSaveStatus
                            dirty={dirty}
                            processing={form.processing}
                        />
                    }
                >
                    <FormSecondaryAction
                        size="sm"
                        disabled={!dirty || form.processing}
                        onClick={() => {
                            form.resetAndClearErrors();
                            branding.discard();
                        }}
                    >
                        {t('Discard')}
                    </FormSecondaryAction>
                    <Button
                        type="submit"
                        form="email-settings-form"
                        size="sm"
                        disabled={!dirty || form.processing}
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
            </form>
        </>
    );
}
