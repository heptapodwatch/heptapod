import { useTranslation } from 'react-i18next';
import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/Heptapod/Http/Controllers/Settings/SecurityController';
import { PageHeader } from '@/components/page-header';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import InputError, { fieldErrorProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const { t } = useTranslation();
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title={t('Security')} />

            <PageHeader
                title={t('Security')}
                description={t('pages.security.description')}
            />

            <div className="space-y-6">
                <div className="space-y-6">
                    <Form
                        noValidate
                        id="security-password-form"
                        {...SecurityController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnError={[
                            'password',
                            'password_confirmation',
                            'current_password',
                        ]}
                        resetOnSuccess
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }

                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="space-y-6"
                    >
                        {({
                            errors,
                            processing,
                            isDirty,
                            resetAndClearErrors,
                        }) => (
                            <>
                                <FormSection
                                    title={t('Update password')}
                                    description={t(
                                        'Use a long password that you use nowhere else.',
                                    )}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="current_password">
                                            {t('Current password')}
                                        </Label>

                                        <PasswordInput
                                            id="current_password"
                                            {...fieldErrorProps(
                                                'current_password',
                                                errors.current_password,
                                            )}
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            className="mt-1 block w-full"
                                            autoComplete="current-password"
                                            placeholder={t('Current password')}
                                        />

                                        <InputError
                                            field="current_password"
                                            message={errors.current_password}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            {t('New password')}
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            {...fieldErrorProps(
                                                'password',
                                                errors.password,
                                            )}
                                            ref={passwordInput}
                                            name="password"
                                            className="mt-1 block w-full"
                                            autoComplete="new-password"
                                            placeholder={t('New password')}
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError
                                            field="password"
                                            message={errors.password}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            {t('Confirm password')}
                                        </Label>

                                        <PasswordInput
                                            id="password_confirmation"
                                            {...fieldErrorProps(
                                                'password_confirmation',
                                                errors.password_confirmation,
                                            )}
                                            name="password_confirmation"
                                            className="mt-1 block w-full"
                                            autoComplete="new-password"
                                            placeholder={t('Confirm password')}
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError
                                            field="password_confirmation"
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>
                                </FormSection>
                                <FormActions
                                    status={
                                        <FormSaveStatus
                                            dirty={isDirty}
                                            processing={processing}
                                        />
                                    }
                                >
                                    <FormSecondaryAction
                                        disabled={!isDirty || processing}
                                        onClick={() => resetAndClearErrors()}
                                    >
                                        {t('Discard')}
                                    </FormSecondaryAction>
                                    <Button
                                        type="submit"
                                        form="security-password-form"
                                        disabled={!isDirty || processing}
                                        data-test="update-password-button"
                                    >
                                        {t('Save')}
                                    </Button>
                                </FormActions>
                            </>
                        )}
                    </Form>
                </div>

                <ManageTwoFactor
                    canManageTwoFactor={props.canManageTwoFactor}
                    requiresConfirmation={props.requiresConfirmation}
                    twoFactorEnabled={props.twoFactorEnabled}
                />

                <ManagePasskeys
                    canManagePasskeys={props.canManagePasskeys}
                    passkeys={props.passkeys}
                />
            </div>
        </>
    );
}
