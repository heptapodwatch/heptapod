import { useTranslation } from 'react-i18next';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet } from '@/components/ui/sheet';

type Props = {
    onSuccess: () => void;
};

/** A default passkey name from the browser and device ("Chrome on Mac"). */
function deviceName(): string {
    const ua = navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    return [browser, os].filter(Boolean).join(' on ') || '';
}

export default function PasskeyRegistration({ onSuccess }: Props) {
    const { t } = useTranslation();
    const [name, setName] = useState(deviceName);
    const [open, setOpen] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setOpen(false);
            setName(deviceName());
            onSuccess();
        },
    });

    if (!isSupported) {
        return (
            <div className="text-sm text-muted-foreground">
                {t('Passkeys are not supported in this browser.')}
            </div>
        );
    }

    const close = () => {
        setOpen(false);
        setName(deviceName());
    };

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                {t('Add passkey')}
            </Button>
            <Sheet
                open={open}
                onOpenChange={(next) => {
                    if (!next && !isLoading) close();
                }}
            >
                <SheetForm
                    title={t('Add passkey')}
                    description={t(
                        "Sign in without a password, using your device's fingerprint, face or PIN.",
                    )}
                    aria-busy={isLoading}
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!name.trim() || isLoading) return;
                        void register(name);
                    }}
                    footer={
                        <>
                            <FormSecondaryAction
                                disabled={isLoading}
                                onClick={close}
                            >
                                {t('Cancel')}
                            </FormSecondaryAction>
                            <Button
                                type="submit"
                                disabled={isLoading || !name.trim()}
                            >
                                {isLoading
                                    ? t('Registering…')
                                    : t('Register passkey')}
                            </Button>
                        </>
                    }
                >
                    <div className="space-y-2">
                        <Label htmlFor="passkey-name">
                            {t('Passkey name')}
                        </Label>
                        <Input
                            id="passkey-name"
                            type="text"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder={t('e.g., MacBook Pro, iPhone')}
                            aria-describedby="passkey-name-help"
                        />
                        <p
                            id="passkey-name-help"
                            className="text-xs text-muted-foreground"
                        >
                            {t('A name helps you identify this passkey later.')}
                        </p>
                    </div>
                    <InputError message={error} />
                </SheetForm>
            </Sheet>
        </>
    );
}
