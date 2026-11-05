import { type ComponentProps, type ReactNode } from 'react';
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

export function SheetForm({
    title,
    description,
    detail,
    footer,
    children,
    contentProps,
    ...formProps
}: ComponentProps<'form'> & {
    title: string;
    /** One sentence. */
    description: string;
    /** Anything beyond that sentence, behind an InfoTooltip. */
    detail?: string;
    footer: ReactNode;
    contentProps?: Omit<
        ComponentProps<typeof SheetContent>,
        'children' | 'className'
    >;
}) {
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
            {...contentProps}
            {...focusReturn}
            className="w-full gap-0 sm:max-w-xl"
        >
            <SheetHeader className="shrink-0 border-b p-6 pr-12">
                <SheetTitle>{title}</SheetTitle>
                <SheetDescription>
                    {description}
                    {detail && (
                        <>
                            {' '}
                            <InfoTooltip>{detail}</InfoTooltip>
                        </>
                    )}
                </SheetDescription>
            </SheetHeader>
            <form
                noValidate
                {...formProps}
                className="flex min-h-0 flex-1 flex-col"
                data-sheet-form
            >
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-6">
                    {children}
                </div>
                <SheetFooter className="sticky bottom-0 shrink-0 border-t bg-background p-6">
                    {footer}
                </SheetFooter>
            </form>
        </SheetContent>
    );
}
