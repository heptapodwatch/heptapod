import { LanguageSelect } from '@/components/language-select';
import {
    BrowserTimezoneHint,
    TimezoneCombobox,
    useInstanceTimezone,
} from '@/components/timezone-combobox';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { setLocale } from '@/lib/i18n';
import { update } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { send } from '@/routes/verification';
import { TextLink } from '@/components/text-link';

export default function Profile({
    mustVerifyEmail,
    status,
    timezones,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    timezones: string[];
}) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const instanceTimezone = useInstanceTimezone();
    const user = auth.user;
    if (!user) throw new Error('Profile requires an authenticated user');
    const initials = useInitials();
    const form = useForm({
        name: user.name,
        email: user.email,
        locale: user.locale,
        timezone: user.timezone_override,
        current_password: '',
    });
    // The server compares addresses trimmed and case-insensitively; only a real change asks for the password.
    const emailChanged =
        form.data.email.trim().toLowerCase() !== user.email.toLowerCase();
    const pendingErrorFocus = useRef<string | undefined>(undefined);
    useEffect(() => {
        if (!form.processing && pendingErrorFocus.current) {
            // Validation focus must wait until the fieldset is enabled again.
            document.getElementById(pendingErrorFocus.current)?.focus();
            pendingErrorFocus.current = undefined;
        }
    }, [form.processing, form.errors]);
    const save = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(update.url(), {
            preserveScroll: true,
            onSuccess: ({ props }) => {
                const savedUser = props.auth.user;
                if (!savedUser)
                    throw new Error(
                        'Profile save requires an authenticated user',
                    );
                const saved = {
                    name: savedUser.name,
                    email: savedUser.email,
                    locale: savedUser.locale,
                    timezone: savedUser.timezone_override,
                    current_password: '',
                };
                form.setDefaults(saved);
                form.setData(saved);
                setLocale(saved.locale);
            },
            onError: (errors) => {
                pendingErrorFocus.current = Object.keys(errors)[0];
            },
        });
    };

    return (
        <>
            <Head title={t('Profile')} />
            <PageHeader
                title={t('Profile')}
                description={t('pages.profile.description')}
            />
            <form
                id="profile-form"
                onSubmit={save}
                noValidate
                className="space-y-4"
                aria-busy={form.processing}
            >
                <fieldset
                    disabled={form.processing}
                    className="min-w-0 space-y-4"
                >
                    <FormSection title={t('You')} description="">
                        <div className="space-y-4">
                            <Avatar className="size-14">
                                <AvatarFallback className="bg-avatar-fallback text-headline text-avatar-fallback-foreground">
                                    {initials(user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="name">{t('Name')}</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        autoComplete="name"
                                        required
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.name}
                                        aria-describedby={
                                            form.errors.name
                                                ? 'name-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="name-error"
                                        message={form.errors.name}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="email">
                                        {t('Email address')}
                                    </Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        autoComplete="username"
                                        required
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.email}
                                        aria-describedby={
                                            form.errors.email
                                                ? 'email-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="email-error"
                                        message={form.errors.email}
                                    />
                                </div>
                                {emailChanged && (
                                    <div className="space-y-1.5 sm:col-start-2">
                                        <Label htmlFor="current_password">
                                            {t('Current password')}
                                        </Label>
                                        <PasswordInput
                                            id="current_password"
                                            name="current_password"
                                            autoComplete="current-password"
                                            required
                                            value={form.data.current_password}
                                            onChange={(event) =>
                                                form.setData(
                                                    'current_password',
                                                    event.target.value,
                                                )
                                            }
                                            {...fieldErrorProps(
                                                'current_password',
                                                form.errors.current_password,
                                                'current_password-hint',
                                            )}
                                        />
                                        <p
                                            id="current_password-hint"
                                            className="text-xs text-muted-foreground"
                                        >
                                            {t(
                                                'profile.email_change_password_hint',
                                            )}
                                        </p>
                                        <InputError
                                            field="current_password"
                                            message={
                                                form.errors.current_password
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                            {mustVerifyEmail &&
                                user.email_verified_at === null && (
                                    <div className="space-y-2 text-sm text-muted-foreground">
                                        <p>
                                            {t(
                                                'Your email address is unverified.',
                                            )}{' '}
                                            <TextLink href={send()} as="button">
                                                {t(
                                                    'Click here to re-send the verification email.',
                                                )}
                                            </TextLink>
                                        </p>
                                        {status ===
                                            'verification-link-sent' && (
                                            <p role="status">
                                                {t(
                                                    'A new verification link has been sent to your email address.',
                                                )}
                                            </p>
                                        )}
                                    </div>
                                )}
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Password, passkeys and two-factor authentication are in',
                                )}{' '}
                                <TextLink href={editSecurity()} prefetch>
                                    {t('Security')}
                                </TextLink>
                                .
                            </p>
                        </div>
                    </FormSection>
                    <FormSection
                        title={t('Language and time')}
                        description={t('Times are shown in your timezone.')}
                    >
                        <div className="space-y-4">
                            <BrowserTimezoneHint
                                onDefault={user.timezone_override === null}
                                current={user.timezone}
                                draft={form.data.timezone}
                                timezones={timezones}
                                onUse={(zone) => form.setData('timezone', zone)}
                            />
                            <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)]">
                                <LanguageSelect
                                    id="locale"
                                    name="locale"
                                    value={form.data.locale}
                                    onValueChange={(value) =>
                                        form.setData('locale', value)
                                    }
                                    error={form.errors.locale}
                                />
                                <div className="space-y-1.5">
                                    <Label htmlFor="timezone">
                                        {t('Timezone')}
                                    </Label>
                                    <TimezoneCombobox
                                        id="timezone"
                                        timezones={timezones}
                                        value={form.data.timezone}
                                        onValueChange={(value: string | null) =>
                                            form.setData('timezone', value)
                                        }
                                        defaultZone={{
                                            zone: instanceTimezone,
                                            source: t(
                                                'timezone.source.instance',
                                            ),
                                        }}
                                        invalid={!!form.errors.timezone}
                                        describedBy={
                                            form.errors.timezone
                                                ? 'timezone-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="timezone-error"
                                        message={form.errors.timezone}
                                    />
                                </div>
                            </div>
                        </div>
                    </FormSection>
                </fieldset>
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
                        onClick={() => {
                            form.resetAndClearErrors();
                        }}
                    >
                        {t('Discard')}
                    </FormSecondaryAction>
                    <Button
                        type="submit"
                        form="profile-form"
                        disabled={!form.isDirty || form.processing}
                        data-test="update-profile-button"
                    >
                        {t('Save')}
                    </Button>
                </FormActions>
            </form>
        </>
    );
}
