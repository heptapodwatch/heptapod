import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { TimezoneCombobox } from '@/components/timezone-combobox';
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
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { setLocale, type Locale } from '@/lib/i18n';
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
    const user = auth.user;
    if (!user) throw new Error('Profile requires an authenticated user');
    const initials = useInitials();
    const form = useForm({
        name: user.name,
        email: user.email,
        locale: user.locale,
        timezone: user.timezone,
    });
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
                    timezone: savedUser.timezone,
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
                                <AvatarFallback className="bg-primary/20 text-headline text-foreground">
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
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)]">
                            <div className="space-y-1.5">
                                <Label id="locale-label">{t('Language')}</Label>
                                <RadioGroup
                                    id="locale"
                                    aria-labelledby="locale-label"
                                    aria-invalid={!!form.errors.locale}
                                    aria-describedby={
                                        form.errors.locale
                                            ? 'locale-error'
                                            : undefined
                                    }
                                    name="locale"
                                    value={String(form.data.locale)}
                                    onValueChange={(value) =>
                                        form.setData('locale', value as Locale)
                                    }
                                >
                                    <RadioGroupItem value={String('en')}>
                                        {t('English')}
                                    </RadioGroupItem>
                                    <RadioGroupItem value={String('de')}>
                                        {t('German')}
                                    </RadioGroupItem>
                                </RadioGroup>
                                <InputError
                                    id="locale-error"
                                    message={form.errors.locale}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="timezone">
                                    {t('Timezone')}
                                </Label>
                                <TimezoneCombobox
                                    id="timezone"
                                    timezones={timezones}
                                    value={String(form.data.timezone)}
                                    onValueChange={(value) =>
                                        form.setData('timezone', value)
                                    }
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
                        size="sm"
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
                        size="sm"
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
