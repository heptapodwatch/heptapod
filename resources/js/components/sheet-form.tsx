import { useId, type ComponentProps, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { Button } from '@/components/ui/button';
import {
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { InfoTooltip } from '@/components/info-tooltip';
import { useReturnFocusToOpener } from '@/lib/focus-return';

/** Form controls a sheet opens on; hint and tooltip triggers are never the first stop. */
const formControlSelector =
    'input, textarea, select, [role="combobox"], [role="radio"], [role="checkbox"], [role="switch"]';

/** The first form control a keyboard user can reach, in document order; a radio group is entered at its checked radio. */
function firstFormControl(container: HTMLElement): HTMLElement | null {
    const control = Array.from(
        container.querySelectorAll<HTMLElement>(formControlSelector),
    ).find(
        (candidate) =>
            // Radix radios are tabIndex -1 until their group is entered; the group itself is the tab stop.
            (candidate.tabIndex >= 0 || candidate.role === 'radio') &&
            !candidate.matches(':disabled, [aria-disabled="true"]') &&
            (candidate.checkVisibility?.() ?? true),
    );
    if (control?.role !== 'radio') return control ?? null;
    return (
        control
            .closest('[role="radiogroup"]')
            ?.querySelector<HTMLElement>(
                '[role="radio"][aria-checked="true"]:not(:disabled)',
            ) ?? control
    );
}

type SheetActions =
    | { kind: 'read-only'; onClose: () => void; reason: string }
    | ({
          onCancel: () => void;
          valid?: boolean;
          blockedReason?: string;
          busy?: boolean;
          pendingLabel?: string;
      } & (
          | { kind: 'create' }
          | {
                kind: 'action';
                label: string;
                icon?: ReactNode;
                secondary?: ReactNode;
            }
          | {
                kind: 'edit';
                dirty: boolean;
                remove?: { label: string; confirm: () => void };
            }
      ));

export function SheetForm({
    title,
    description,
    detail,
    footer,
    actions,
    children,
    contentProps,
    onSubmit,
    ...formProps
}: ComponentProps<'form'> & {
    title: string;
    /** One consequence sentence, or none. */
    description?: string;
    /** Anything beyond that sentence, behind an InfoTooltip. */
    detail?: string;
    contentProps?: Omit<
        ComponentProps<typeof SheetContent>,
        'children' | 'className'
    >;
} & (
        | { actions: SheetActions; footer?: never }
        | { footer: ReactNode; actions?: never }
    )) {
    const { t } = useTranslation();
    const reasonId = useId();
    const editing = actions?.kind === 'edit';
    const readOnly = actions?.kind === 'read-only';
    const reason = readOnly ? actions.reason : actions?.blockedReason;
    const blocked =
        actions &&
        (readOnly ||
            actions.busy ||
            actions.valid === false ||
            !!reason ||
            (editing && !actions.dirty));
    const remove = editing ? actions.remove : undefined;
    // Sheets open from state, not a Radix SheetTrigger, so Radix has nothing to return focus to; the sheet does it on close.
    const focusReturn = useReturnFocusToOpener({
        onOpenAutoFocus: (event) => {
            contentProps?.onOpenAutoFocus?.(event);
            if (event.defaultPrevented) return;
            const control = firstFormControl(
                event.currentTarget as HTMLElement,
            );
            if (control === null) return;
            event.preventDefault();
            control.focus();
        },
        onCloseAutoFocus: contentProps?.onCloseAutoFocus,
    });
    return (
        <SheetContent
            {...(description === undefined && {
                'aria-describedby': undefined,
            })}
            {...contentProps}
            {...focusReturn}
            className="w-full gap-0 sm:max-w-xl"
        >
            <SheetHeader className="shrink-0 border-b p-6 pr-12">
                {/* outline-none: a check settings sheet focuses its title on open (no outline flash). */}
                <SheetTitle className="outline-none">{title}</SheetTitle>
                {description !== undefined && (
                    <SheetDescription>
                        {description}
                        {detail && (
                            <>
                                {' '}
                                <InfoTooltip>{detail}</InfoTooltip>
                            </>
                        )}
                    </SheetDescription>
                )}
            </SheetHeader>
            <form
                noValidate
                {...formProps}
                onSubmit={(event) => {
                    if (!actions) {
                        onSubmit?.(event);
                        return;
                    }
                    event.preventDefault();
                    if (!blocked) onSubmit?.(event);
                }}
                className="flex min-h-0 flex-1 flex-col"
                data-sheet-form
            >
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-6">
                    {children}
                </div>
                {actions ? (
                    <SheetFooter className="sticky bottom-0 shrink-0 flex-col border-t bg-background p-6 sm:flex-col">
                        {reason && (
                            <p
                                id={reasonId}
                                role="status"
                                className="text-xs text-muted-foreground sm:text-right"
                            >
                                {reason}
                            </p>
                        )}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                            {readOnly ? (
                                <FormSecondaryAction
                                    onClick={actions.onClose}
                                    aria-describedby={reasonId}
                                >
                                    {t('Close')}
                                </FormSecondaryAction>
                            ) : (
                                <>
                                    {remove && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-destructive sm:mr-auto"
                                            disabled={actions.busy}
                                            onClick={remove.confirm}
                                        >
                                            {remove.label}
                                        </Button>
                                    )}
                                    <FormSecondaryAction
                                        disabled={actions.busy}
                                        onClick={actions.onCancel}
                                    >
                                        {t('Cancel')}
                                    </FormSecondaryAction>
                                    {actions.kind === 'action' &&
                                        actions.secondary}
                                    <Button
                                        type="submit"
                                        disabled={blocked}
                                        aria-describedby={
                                            reason ? reasonId : undefined
                                        }
                                    >
                                        {actions.kind === 'action' &&
                                            actions.icon}
                                        {actions.pendingLabel ??
                                            (editing
                                                ? t('Save')
                                                : actions.kind === 'action'
                                                  ? actions.label
                                                  : title)}
                                    </Button>
                                </>
                            )}
                        </div>
                    </SheetFooter>
                ) : (
                    <SheetFooter className="sticky bottom-0 shrink-0 border-t bg-background p-6">
                        {footer}
                    </SheetFooter>
                )}
            </form>
        </SheetContent>
    );
}
