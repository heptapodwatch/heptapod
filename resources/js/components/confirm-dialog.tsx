import { useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/** The terminal callbacks of an Inertia visit, each of which settles a confirmation. */
export type ConfirmVisitCallbacks = {
    onSuccess: () => void;
    onError: () => void;
    onCancel: () => void;
    onHttpException: () => void;
    onNetworkError: () => void;
    onFinish: () => void;
};

/**
 * Wraps one Inertia visit for ConfirmDialog's onConfirm: true on success, false on every terminal non-success.
 * The exception callbacks return nothing, so Inertia's own error reporting still runs. onFinish settles anything left
 * (it runs after onSuccess, so a success is never turned into a failure).
 */
export function confirmVisit(
    send: (callbacks: ConfirmVisitCallbacks) => void,
): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
        const fail = () => resolve(false);
        send({
            onSuccess: () => resolve(true),
            onError: fail,
            onCancel: fail,
            onHttpException: fail,
            onNetworkError: fail,
            onFinish: fail,
        });
    });
}

/**
 * The one confirmation dialog. Focus opens on Cancel (the safe action); Escape and the overlay cancel.
 * While onConfirm is pending the confirm button is disabled, so a second click cannot submit twice.
 * Red only for destructive (irreversible) actions. On close, DialogContent returns focus to the element that opened it.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel,
    destructive = false,
    onConfirm,
    confirmTestId,
    confirmDisabled = false,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    cancelLabel?: string;
    destructive?: boolean;
    /** Resolves true when the action succeeded (the dialog closes) or false when it failed (the dialog stays open). */
    onConfirm: () => Promise<boolean>;
    /** Keeps the existing data-test hooks that browser tests click. */
    confirmTestId?: string;
    /** Blocks confirm until the body is ready (a typed name, a loaded count). */
    confirmDisabled?: boolean;
    /** Extra body content: a choice, a preview list or an error message. */
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const [pending, setPending] = useState(false);
    const cancel = useRef<HTMLButtonElement>(null);
    const confirm = () => {
        setPending(true);
        void onConfirm()
            .then((succeeded) => {
                if (succeeded) onOpenChange(false);
            })
            .finally(() => setPending(false));
    };
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!pending) onOpenChange(next);
            }}
        >
            <DialogContent
                showCloseButton={false}
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    cancel.current?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {children}
                <DialogFooter>
                    <FormSecondaryAction
                        ref={cancel}
                        disabled={pending}
                        onClick={() => onOpenChange(false)}
                    >
                        {cancelLabel ?? t('Cancel')}
                    </FormSecondaryAction>
                    <Button
                        type="button"
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={pending || confirmDisabled}
                        data-test={confirmTestId}
                        aria-busy={pending || undefined}
                        onClick={confirm}
                    >
                        {pending && <Spinner aria-hidden />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
