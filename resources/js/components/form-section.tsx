import { SectionHeading } from '@/components/section-heading';
import { useId, type ComponentProps, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
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

export function FormActions({
    status,
    back,
    children,
    className,
    ...props
}: ComponentProps<'div'> & { status?: ReactNode; back?: ReactNode }) {
    return (
        <div
            data-form-actions
            className={cn(
                'flex flex-wrap items-center gap-2 rounded-lg border bg-card px-4 py-3',
                className,
            )}
            {...props}
        >
            {status && (
                <p
                    role="status"
                    className="flex basis-full items-center gap-2 text-xs text-muted-foreground sm:basis-auto"
                >
                    {status}
                </p>
            )}
            {back}
            <div className="ml-auto flex flex-wrap items-center gap-2">
                {children}
            </div>
        </div>
    );
}
