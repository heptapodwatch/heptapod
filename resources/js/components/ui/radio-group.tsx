import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import { useEffect, useId, type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type RadioGroupProps = Omit<
    ComponentProps<typeof RadioGroupPrimitive.Root>,
    'orientation'
> & {
    /** Horizontal by default; pass `both` for two-dimensional grids (RadioCards) so all four arrows move focus. */
    orientation?: 'horizontal' | 'vertical' | 'both';
};

export function RadioGroup({
    className,
    id,
    'aria-labelledby': labelledBy,
    orientation = 'horizontal',
    ...props
}: RadioGroupProps) {
    const generatedId = useId();
    const groupId = id ?? generatedId;
    useEffect(() => {
        const labels = (labelledBy?.split(/\s+/) ?? [])
            .map((labelId) => document.getElementById(labelId))
            .filter((label) => label !== null);
        // A radiogroup div cannot receive native label activation.
        const focusRadio = () => {
            const group = document.getElementById(groupId);
            const radio =
                group?.querySelector<HTMLButtonElement>(
                    '[role="radio"][aria-checked="true"]:not(:disabled)',
                ) ?? group?.querySelector<HTMLButtonElement>(
                    '[role="radio"]:not(:disabled)',
                );
            radio?.focus();
        };
        labels.forEach((label) => label.addEventListener('click', focusRadio));
        return () => {
            labels.forEach((label) => label.removeEventListener('click', focusRadio));
        };
    }, [groupId, labelledBy]);
    return (
        <RadioGroupPrimitive.Root
            id={groupId}
            aria-labelledby={labelledBy}
            data-slot="radio-group"
            orientation={orientation === 'both' ? undefined : orientation}
            className={cn(
                'flex gap-x-4 gap-y-1',
                orientation === 'vertical' ? 'flex-col' : 'flex-wrap',
                className,
            )}
            {...props}
        />
    );
}

/** The one radio mark (Radix item + indicator), shared by RadioGroupItem and RadioCards. */
export function RadioMark({
    className,
    focusRing = true,
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Item> & {
    focusRing?: boolean;
}) {
    return (
        <RadioGroupPrimitive.Item
            data-slot="radio-group-item"
            className={cn(
                'size-4 shrink-0 cursor-pointer rounded-full border border-control-border text-primary disabled:cursor-not-allowed disabled:border-border disabled:bg-disabled disabled:text-disabled-primary-foreground disabled:data-[state=checked]:border-border data-[state=checked]:border-primary',
                focusRing ? 'focus-ring' : 'outline-none',
                className,
            )}
            {...props}
        >
            <RadioGroupPrimitive.Indicator className="flex items-center justify-center">
                <span className="size-2 rounded-full bg-current" />
            </RadioGroupPrimitive.Indicator>
        </RadioGroupPrimitive.Item>
    );
}

export function RadioGroupItem({
    children,
    className,
    id,
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Item>) {
    const generatedId = useId();
    const itemId = id ?? generatedId;
    return (
        <label
            htmlFor={itemId}
            className="flex min-h-10 cursor-pointer items-center gap-2 text-cell has-disabled:cursor-not-allowed has-disabled:text-disabled-foreground sm:min-h-8"
        >
            <RadioMark id={itemId} className={className} {...props} />
            {children}
        </label>
    );
}
