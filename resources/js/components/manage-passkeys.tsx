import { useTranslation } from 'react-i18next';
import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import { EmptyState } from '@/components/empty-state';
import { FormSection } from '@/components/form-section';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import type { Passkey } from '@/types/auth';
import { confirmVisit } from '@/components/confirm-dialog';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

export default function ManagePasskeys(props: Props) {
    const { t } = useTranslation();
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number) =>
        confirmVisit((callbacks) =>
            router.delete(destroy.url(id), {
                preserveScroll: true,
                ...callbacks,
            }),
        );

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <FormSection
            title={t('Passkeys')}
            description={t(
                "Sign in without a password, using your device's fingerprint, face or PIN.",
            )}
        >
            <div className="overflow-hidden rounded-lg border border-border">
                {passkeys.length > 0 ? (
                    passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))
                ) : (
                    <EmptyState
                        bare
                        icon={KeyRound}
                        title={t('No passkeys yet')}
                        description={t(
                            'Add a passkey to sign in without a password.',
                        )}
                    />
                )}
            </div>

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </FormSection>
    );
}
