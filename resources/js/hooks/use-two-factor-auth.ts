import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { httpErrorMessage, useHttpRequest } from '@/hooks/use-http-request';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';

export type UseTwoFactorAuthReturn = {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    recoveryCodesList: string[];
    hasSetupData: boolean;
    errors: string[];
    clearErrors: () => void;
    clearSetupData: () => void;
    clearTwoFactorAuthData: () => void;
    fetchSetupData: () => Promise<void>;
    fetchRecoveryCodes: () => Promise<void>;
};

export const OTP_MAX_LENGTH = 6;

export const useTwoFactorAuth = (): UseTwoFactorAuthReturn => {
    const { t } = useTranslation();
    const { submit: submitQr } = useHttpRequest();
    const { submit: submitKey } = useHttpRequest();
    const { submit: submitRecovery } = useHttpRequest();

    const [qrCodeSvg, setQrCodeSvg] = useState<string | null>(null);
    const [manualSetupKey, setManualSetupKey] = useState<string | null>(null);
    const [recoveryCodesList, setRecoveryCodesList] = useState<string[]>([]);
    const [errors, setErrors] = useState<string[]>([]);

    const hasSetupData = qrCodeSvg !== null && manualSetupKey !== null;

    const clearErrors = useCallback((): void => {
        setErrors([]);
    }, []);

    const clearSetupData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
    }, []);

    const clearTwoFactorAuthData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
        setRecoveryCodesList([]);
    }, []);

    const fetchRecoveryCodes = useCallback(async (): Promise<void> => {
        try {
            setErrors([]);
            const codes = (await submitRecovery(recoveryCodes())) as string[];
            setRecoveryCodesList(codes);
        } catch (error: unknown) {
            setErrors((prev) => [
                ...prev,
                httpErrorMessage(error, t, 'errors.two_factor.recovery_failed'),
            ]);
            setRecoveryCodesList([]);
        }
    }, [submitRecovery, t]);

    const fetchSetupData = useCallback(async (): Promise<void> => {
        setErrors([]);
        const [qrResult, keyResult] = await Promise.allSettled([
            submitQr(qrCode()) as Promise<{ svg: string; url: string }>,
            submitKey(secretKey()) as Promise<{ secretKey: string }>,
        ]);
        if (
            qrResult.status === 'fulfilled' &&
            keyResult.status === 'fulfilled'
        ) {
            setQrCodeSvg(qrResult.value.svg);
            setManualSetupKey(keyResult.value.secretKey);
            return;
        }
        const nextErrors: string[] = [];
        if (qrResult.status === 'rejected') {
            nextErrors.push(
                httpErrorMessage(
                    qrResult.reason,
                    t,
                    'errors.two_factor.qr_failed',
                ),
            );
        }
        if (keyResult.status === 'rejected') {
            nextErrors.push(
                httpErrorMessage(
                    keyResult.reason,
                    t,
                    'errors.two_factor.key_failed',
                ),
            );
        }
        setErrors(nextErrors);
        setQrCodeSvg(null);
        setManualSetupKey(null);
    }, [submitQr, submitKey, t]);

    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        hasSetupData,
        errors,
        clearErrors,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        fetchRecoveryCodes,
    };
};
