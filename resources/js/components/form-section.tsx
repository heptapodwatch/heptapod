import { SectionHeading } from '@/components/section-heading';
import {
    createContext,
    useContext,
    useId,
    useState,
    type ComponentProps,
    type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { Button } from '@/components/ui/button';
import { InfoTooltip } from '@/components/info-tooltip';

export function FormSection({
    title,
    description,
    detail,
    children,
}: {
    title: string;
    /** One sentence. */
    description: string;
    /** Anything beyond that sentence, behind an InfoTooltip. */
    detail?: string;
    children: ReactNode;
}) {
    const titleId = useId();
    return (
        <section
            aria-labelledby={titleId}
            data-form-section
            className="@container/form-section border-b py-6"
        >
            <div className="grid gap-4 @3xl/form-section:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] @3xl/form-section:gap-12">
                <div className="space-y-1">
                    <SectionHeading id={titleId}>{title}</SectionHeading>
                    <p className="text-sm text-muted-foreground">
                        {description}
                        {detail && (
                            <>
                                {' '}
                                <InfoTooltip>{detail}</InfoTooltip>
                            </>
                        )}
                    </p>
                </div>
                <div className="min-w-0 space-y-4">{children}</div>
            </div>
        </section>
    );
}

export function FormSecondaryAction(
    props: Omit<ComponentProps<typeof Button>, 'type' | 'variant'>,
) {
    return <Button {...props} type="button" variant="outline" />;
}

// undefined: no FormActionsDock above; null: the dock has not mounted yet.
const FormActionsSlot = createContext<HTMLElement | null | undefined>(
    undefined,
);

/** The one bottom edge FormActions docks into: in flow at the end of `main` (never covering the last content), sticky to the viewport bottom. */
export function FormActionsDock({ children }: { children: ReactNode }) {
    const [slot, setSlot] = useState<HTMLElement | null>(null);
    return (
        <FormActionsSlot.Provider value={slot}>
            {children}
            <div
                ref={setSlot}
                className="sticky bottom-0 z-20 mt-auto empty:hidden"
            />
        </FormActionsSlot.Provider>
    );
}

/** The status of a dirty-gated form: No unsaved changes / Unsaved changes / Saving…. */
export function FormSaveStatus({
    dirty,
    processing,
}: {
    dirty: boolean;
    processing: boolean;
}) {
    const { t } = useTranslation();
    if (processing) return t('Saving…');
    if (!dirty) return t('No unsaved changes');
    return (
        <span className="inline-flex items-center gap-2">
            <StatusDot state="warning" label="Unsaved changes" />
            {t('Unsaved changes')}
        </span>
    );
}

/** The page's one save bar. It renders outside its form element, so a submit button names that form's id in its `form` attribute. */
export function FormActions({
    status,
    back,
    children,
    ...props
}: Omit<ComponentProps<'div'>, 'className'> & {
    status?: ReactNode;
    back?: ReactNode;
}) {
    const slot = useContext(FormActionsSlot);
    if (slot === undefined) {
        throw new Error('FormActions renders only inside FormActionsDock.');
    }
    if (slot === null) return null;
    return createPortal(
        <div
            data-form-actions
            className="border-t bg-background/90 backdrop-blur-sm"
            {...props}
        >
            <div className="mx-auto flex w-full max-w-7xl items-center gap-2 px-4 py-3 md:px-6">
                {status && (
                    <p
                        role="status"
                        className="min-w-0 truncate text-xs text-muted-foreground"
                    >
                        {status}
                    </p>
                )}
                {back}
                <div className="ml-auto flex shrink-0 items-center gap-2">
                    {children}
                </div>
            </div>
        </div>,
        slot,
    );
}
