import { useTranslation } from 'react-i18next';
import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FormSection } from '@/components/form-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const { t } = useTranslation();
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    // Enabling swaps "Enable 2FA" for "Continue setup" (or "Disable 2FA") while the dialog is open, so the opener is
    // gone when it closes; focus goes to the action that now stands in its place.
    const actions = useRef<HTMLDivElement>(null);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <FormSection
            title={t('Two-factor authentication')}
            description={t(
                'Ask for a code from an authenticator app at every sign-in.',
            )}
        >
            {twoFactorEnabled ? (
                <div
                    ref={actions}
                    className="flex flex-col items-start justify-start space-y-4"
                >
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'On. Every sign-in asks for a code from your authenticator app.',
                        )}
                    </p>

                    <div className="relative inline">
                        <Form noValidate {...disable.form()}>
                            {({ processing }) => (
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                >
                                    {t('Disable 2FA')}
                                </Button>
                            )}
                        </Form>
                    </div>

                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />
                </div>
            ) : (
                <div
                    ref={actions}
                    className="flex flex-col items-start justify-start space-y-4"
                >
                    <p className="text-sm text-muted-foreground">
                        {t('Off. Your password alone protects your account.')}
                    </p>

                    <div>
                        {hasSetupData ? (
                            <Button onClick={() => setShowSetupModal(true)}>
                                <ShieldCheck />
                                {t('Continue setup')}
                            </Button>
                        ) : (
                            <Form
                                noValidate
                                {...enable.form()}
                                onSuccess={() => setShowSetupModal(true)}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {t('Enable 2FA')}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                onCloseAutoFocus={(event) => {
                    const action =
                        actions.current?.querySelector<HTMLButtonElement>(
                            'button:not(:disabled)',
                        );
                    if (!action) return;
                    event.preventDefault();
                    action.focus();
                }}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </FormSection>
    );
}
