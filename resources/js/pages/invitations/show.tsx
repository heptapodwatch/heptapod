import { useRef, useState } from 'react';
import { Form, Head, usePage } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import { useTranslation } from 'react-i18next';
import { store } from '@/actions/Heptapod/Http/Controllers/InvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { TooltipHint } from '@/components/truncated-text';
import { useFormat } from '@/hooks/use-format';
import InvitationLayout from '@/layouts/auth/invitation-layout';
import { login } from '@/routes';
import { TextLink } from '@/components/text-link';

type Props = { instance: string } & (
    | {
          state: 'valid';
          invitation: Heptapod.Data.Ui.InvitationData;
          token: string;
          passwordRules: string;
      }
    | {
          state: 'unavailable' | 'expired' | 'active-account';
          invitation: null;
          token?: never;
          passwordRules?: never;
      }
);

export default function Invitation({
    invitation,
    instance,
    state,
    token,
    passwordRules,
}: Props) {
    const { t } = useTranslation();
    const { timezone } = usePage().props;
    const format = useFormat();
    const [joinError, setJoinError] = useState<
        'uncertain' | 'throttled' | 'expired-session' | null
    >(null);
    const uncertain = joinError === 'uncertain';
    const errorRef = useRef<HTMLParagraphElement>(null);
    const formRef = useRef<FormComponentRef>(null);
    const fail = (
        error: 'uncertain' | 'throttled' | 'expired-session' = 'uncertain',
    ) => {
        formRef.current?.reset('password', 'password_confirmation');
        setJoinError(error);
        requestAnimationFrame(() => errorRef.current?.focus());
        return false;
    };
    const joinErrors = {
        uncertain:
            'The join result could not be confirmed. Do not submit again. Check whether you can sign in, or ask the team to check your invitation.',
        throttled: 'Too many attempts. Try again shortly.',
        'expired-session':
            'Your session has expired. Reload this page before joining.',
    } as const;
    const unavailable = {
        unavailable: [
            'Invitation unavailable',
            'This link is invalid, already used or revoked. Ask the team for a new invitation.',
        ],
        expired: [
            'Invitation expired',
            'This invitation has expired. Ask the team for a new invitation.',
        ],
        'active-account': [
            'Account already active',
            'This email already has an active account. Sign in instead.',
        ],
    } as const;
    return (
        <>
            <Head title={t('Invitation')}>
                <meta name="referrer" content="no-referrer" />
            </Head>
            {state !== 'valid' ? (
                <>
                    <h1>{t(unavailable[state][0])}</h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {t(unavailable[state][1])}
                    </p>
                    <TextLink className="text-sm" href={login()}>
                        {t('Log in')}
                    </TextLink>
                </>
            ) : (
                <>
                    <header className="flex flex-col gap-1">
                        <h1>{t('Join :instance', { instance })}</h1>
                        <p className="text-sm leading-[1.45] text-muted-foreground">
                            {invitation.inviter
                                ? t(':name invited you to their monitoring.', {
                                      name: invitation.inviter,
                                  })
                                : t(
                                      'You have been invited to this monitoring instance.',
                                  )}
                        </p>
                    </header>
                    <dl className="grid grid-cols-[90px_minmax(0,1fr)] gap-x-3 gap-y-2 rounded-[10px] border border-border p-[18px] text-cell leading-[1.45]">
                        <dt className="text-muted-foreground">
                            {t('Your role')}
                        </dt>
                        <dd>{t(invitation.role)}</dd>
                        <dt className="text-muted-foreground">
                            {t('Your clients')}
                        </dt>
                        <dd className="min-w-0 break-words">
                            {invitation.client_scope === 'all' ? (
                                t('All clients')
                            ) : (
                                <ul className="space-y-1">
                                    {invitation.clients.map((client) => (
                                        <li key={client.id}>
                                            {client.name} ·{' '}
                                            {t(':count sites', {
                                                count: client.sites_count,
                                            })}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </dd>
                        <dt className="text-muted-foreground">{t('Email')}</dt>
                        <dd className="font-mono break-all">
                            {invitation.email}
                        </dd>
                    </dl>
                    <Form
                        noValidate
                        ref={formRef}
                        {...store.form(token)}
                        disableWhileProcessing
                        resetOnError={['password', 'password_confirmation']}
                        resetOnSuccess={['password', 'password_confirmation']}
                        onStart={() => setJoinError(null)}
                        onHttpException={(response) => {
                            if (response.status === 429)
                                return fail('throttled');
                            if (response.status === 419)
                                return fail('expired-session');
                            if (
                                [404, 409, 410].includes(response.status) &&
                                typeof response.data === 'object' &&
                                response.data !== null &&
                                'component' in response.data &&
                                response.data.component === 'invitations/show'
                            )
                                return;
                            return fail();
                        }}
                        onNetworkError={() => fail()}
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-[22px]">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">
                                        {t('Your name')}
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        autoComplete="name"
                                        required
                                        maxLength={255}
                                        autoFocus
                                        aria-invalid={!!errors.name}
                                        aria-describedby={
                                            errors.name
                                                ? 'name-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="name-error"
                                        message={errors.name}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password">
                                        {t('Password')}
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        autoComplete="new-password"
                                        required
                                        passwordrules={passwordRules}
                                        aria-invalid={!!errors.password}
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="password-error"
                                        message={errors.password}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password_confirmation">
                                        {t('Confirm password')}
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        required
                                        passwordrules={passwordRules}
                                        aria-invalid={
                                            !!errors.password_confirmation
                                        }
                                        aria-describedby={
                                            errors.password_confirmation
                                                ? 'confirmation-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="confirmation-error"
                                        message={errors.password_confirmation}
                                    />
                                </div>
                                <InputError
                                    ref={errorRef}
                                    tabIndex={-1}
                                    message={
                                        joinError ? joinErrors[joinError] : null
                                    }
                                />
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing || uncertain}
                                >
                                    {processing && <Spinner />}
                                    {t('Join')}
                                </Button>
                                {uncertain && (
                                    <TextLink
                                        className="text-sm"
                                        href={login()}
                                    >
                                        {t('Log in')}
                                    </TextLink>
                                )}
                            </div>
                        )}
                    </Form>
                    <TooltipHint content={format.exact(invitation.expires_at)}>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {t('Valid until :time', {
                                time: format.timestamp(invitation.expires_at),
                            })}{' '}
                            ({timezone})
                        </p>
                    </TooltipHint>
                </>
            )}
        </>
    );
}

Invitation.layout = [InvitationLayout];
