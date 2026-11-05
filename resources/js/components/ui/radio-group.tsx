import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import { useEffect, useId, type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function RadioGroup({
    className,
    id,
    'aria-labelledby': labelledBy,
    orientation = 'horizontal',
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Root>) {
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
            orientation={orientation}
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
    ...props
}: ComponentProps<typeof RadioGroupPrimitive.Item>) {
    return (
        <RadioGroupPrimitive.Item
            data-slot="radio-group-item"
            className={cn(
                'size-4 shrink-0 cursor-pointer rounded-full border border-control-border text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed data-[state=checked]:border-primary',
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
            className="flex min-h-10 cursor-pointer items-center gap-2 text-sm has-disabled:cursor-not-allowed has-disabled:opacity-50 sm:min-h-8"
        >
            <RadioMark id={itemId} className={className} {...props} />
            {children}
        </label>
    );
}
