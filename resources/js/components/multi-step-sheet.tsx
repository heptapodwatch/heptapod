import { Stepper } from '@/components/stepper';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

export type SheetStep = {
    title: string;
    content: ReactNode;
    validate?: () => boolean;
    focusId?: string;
};

export function MultiStepFormSheet({
    open,
    onOpenChange,
    title,
    description,
    steps,
    trigger,
    onComplete,
    completeLabel,
    disabled = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    steps: SheetStep[];
    trigger?: ReactNode;
    onComplete: () => void;
    completeLabel: string;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const [step, setStep] = useState(0);
    const content = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (open) setStep(0);
    }, [open]);
    const focusId = steps[step]?.focusId;
    useEffect(() => {
        if (open) {
            content.current?.closest('[role="dialog"]')?.scrollTo({ top: 0 });
            const target =
                (focusId ? document.getElementById(focusId) : null) ??
                content.current;
            target?.focus({ preventScroll: true });
        }
    }, [open, step, focusId]);
    const current = steps[step];
    if (!current)
        throw new Error('MultiStepFormSheet requires at least one step');
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>{t(title)}</SheetTitle>
                    <SheetDescription>{t(description)}</SheetDescription>
                </SheetHeader>
                <form
                    noValidate
                    className="flex flex-1 flex-col gap-6 px-4 pb-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (disabled || current.validate?.() === false) return;
                        if (step < steps.length - 1) setStep(step + 1);
                        else onComplete();
                    }}
                >
                    <Stepper
                        label={t(title)}
                        steps={steps.map((item) => t(item.title))}
                        current={step}
                    />
                    <div
                        ref={content}
                        tabIndex={-1}
                        aria-label={t(current.title)}
                        className="space-y-6 outline-none"
                    >
                        {current.content}
                    </div>
                    <SheetFooter className="px-0">
                        <FormSecondaryAction
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        {step > 0 && (
                            <FormSecondaryAction
                                onClick={() => setStep(step - 1)}
                                disabled={disabled}
                            >
                                {t('Back')}
                            </FormSecondaryAction>
                        )}
                        <Button type="submit" disabled={disabled}>
                            {t(
                                step === steps.length - 1
                                    ? completeLabel
                                    : 'Next',
                            )}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
