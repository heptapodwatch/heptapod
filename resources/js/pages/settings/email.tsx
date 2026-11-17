import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import { InfoTooltip } from '@/components/info-tooltip';
import InputError, { fieldErrorProps } from '@/components/input-error';
import {
    AppearanceFields,
    AppearancePreview,
    localErrors,
    useLogoDraft,
    useSavedLogo,
} from '@/components/email-appearance';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { update } from '@/routes/settings/email';
import { Head, useForm } from '@inertiajs/react';
import { type ReactNode } from 'react';
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

/** The saved state of every field; logos are saved files (null) until the user picks or removes one. */
function savedValues(
    instancePage: Heptapod.Data.Ui.InstanceSettingsData,
    emailPage: Heptapod.Data.Ui.EmailSettingsData,
) {
    return {
        mail_design: instancePage.mail_design,
        sender_name: emailPage.sender_name ?? '',
        accent: emailPage.accent ?? '',
        footer: emailPage.footer ?? '',
        logo: null as File | null,
        logo_dark: null as File | null,
        remove_logo: false,
        remove_logo_dark: false,
    };
}

export default function Email({
    instancePage,
    emailPage,
}: Heptapod.Data.Ui.EmailPageData) {
    const { t } = useTranslation();
    const form = useForm(savedValues(instancePage, emailPage));
    const savedLogo = useSavedLogo(emailPage.logo_url);
    const savedLogoDark = useSavedLogo(emailPage.logo_dark_url);
    const logoFile =
        form.data.logo ?? (form.data.remove_logo ? null : savedLogo);
    const logoDarkFile = logoFile
        ? (form.data.logo_dark ??
          (form.data.remove_logo_dark ? null : savedLogoDark))
        : null;
    // Only readable images reach the preview; an unreadable one stays in its field with the error.
    const logo = useLogoDraft(logoFile);
    const logoDark = useLogoDraft(logoDarkFile);
    const text = {
        sender_name: form.data.sender_name,
        accent: form.data.accent,
        footer: form.data.footer,
    };
    const local = localErrors(
        text,
        {
            senderNameMax: emailPage.sender_name_max,
            footerMax: emailPage.footer_max,
        },
        t,
    );
    // A server refusal stays at its field until that field changes; the page's own checks show while typing.
    const errors = {
        sender_name: form.errors.sender_name ?? local.sender_name,
        accent: form.errors.accent ?? local.accent,
        footer: form.errors.footer ?? local.footer,
        logo: form.errors.logo,
        logo_dark: form.errors.logo_dark,
    };
    const mailDesignField = fieldErrorProps(
        'email-mail-design',
        form.errors.mail_design,
        'email-mail-design-description',
    );
    const configured = (value: boolean) =>
        t(value ? 'Configured' : 'Not configured');
    const absent = t('Not configured');

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
                    // PHP parses a multipart body (a logo upload) only for POST, so the update is a spoofed PATCH.
                    form.transform((data) => ({ ...data, _method: 'patch' }));
                    form.post(update.url(), {
                        preserveScroll: true,
                        forceFormData: true,
                        onSuccess: ({ props }) => {
                            const page =
                                props as unknown as Heptapod.Data.Ui.EmailPageData;
                            const saved = savedValues(
                                page.instancePage,
                                page.emailPage,
                            );
                            form.setDefaults(saved);
                            form.setData(saved);
                        },
                    });
                }}
            >
                <FormSection
                    title={t('mail_branding.section.title')}
                    description={t('mail_branding.section.description')}
                    detail={t('mail_branding.section.detail')}
                    preview={
                        <AppearancePreview
                            design={form.data.mail_design}
                            text={text}
                            invalid={local}
                            logo={logo}
                            logoDark={logoDark}
                            fromName={instancePage.from_name}
                            fromAddress={instancePage.from_address}
                        />
                    }
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
                    <AppearanceFields
                        text={text}
                        logoFile={logoFile}
                        logoDarkFile={logoDarkFile}
                        errors={errors}
                        onText={(field, value) => {
                            form.setData(field, value);
                            form.clearErrors(field);
                        }}
                        onLogo={(file) => {
                            form.setData((data) => ({
                                ...data,
                                logo: file,
                                remove_logo: false,
                            }));
                            form.clearErrors('logo');
                        }}
                        onRemoveLogo={() => {
                            // Without a logo there is no dark-mode logo either.
                            form.setData((data) => ({
                                ...data,
                                logo: null,
                                remove_logo: true,
                                logo_dark: null,
                                remove_logo_dark: true,
                            }));
                            form.clearErrors('logo', 'logo_dark');
                        }}
                        onLogoDark={(file) => {
                            form.setData((data) => ({
                                ...data,
                                logo_dark: file,
                                remove_logo_dark: false,
                            }));
                            form.clearErrors('logo_dark');
                        }}
                        onRemoveLogoDark={() => {
                            form.setData((data) => ({
                                ...data,
                                logo_dark: null,
                                remove_logo_dark: true,
                            }));
                            form.clearErrors('logo_dark');
                        }}
                        fromName={instancePage.from_name}
                        fromAddress={instancePage.from_address}
                        footerMax={emailPage.footer_max}
                        plain={form.data.mail_design === 'plain'}
                        disabled={form.processing}
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
                            dirty={form.isDirty}
                            processing={form.processing}
                        />
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
                        form="email-settings-form"
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
