import { useId, type ReactNode } from 'react';
import { RadioGroup, RadioMark } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

/** Consequence-led single choice: one card per option with label, consequence and optional badge (Radix radios, arrow keys). */
export function RadioCards<T extends string>({
    label,
    labelledBy,
    value,
    options,
    onChange,
    disabled = false,
    invalid = false,
    describedBy,
}: ({ label: string; labelledBy?: never } | { label?: never; labelledBy: string }) & {
    value: T;
    /** A disabled option shows a state the user reaches another way (alert sensitivity's derived Custom). */
    options: { value: T; label: string; description: string; badge?: ReactNode; disabled?: boolean }[];
    onChange: (value: T) => void;
    disabled?: boolean;
    /** A validation error on the choice: aria-invalid on the group; pass the error id in describedBy. */
    invalid?: boolean;
    describedBy?: string;
}) {
    const id = useId();
    return (
        <RadioGroup
            aria-label={label}
            aria-labelledby={labelledBy}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            orientation="both"
            value={value}
            onValueChange={(next) => onChange(next as T)}
            disabled={disabled}
            className="@container/radio-cards block"
        >
            <div className="grid gap-2 @xl/radio-cards:grid-cols-2">
                {options.map((option) => {
                    const isSelected = value === option.value;
                    const isDisabled = disabled || option.disabled;
                    return (
                        <label
                            key={option.value}
                            htmlFor={`${id}-${option.value}`}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-[color,background-color,border-color,box-shadow] duration-(--motion-press) ease-out has-focus-visible:ring-2 has-focus-visible:ring-ring has-disabled:cursor-not-allowed',
                                isSelected &&
                                    !isDisabled &&
                                    'border-primary bg-accent/50',
                                isSelected &&
                                    isDisabled &&
                                    'border-border bg-disabled-primary text-disabled-foreground',
                                !isSelected &&
                                    isDisabled &&
                                    'border-dashed border-border bg-disabled text-disabled-foreground',
                            )}
                        >
                            <RadioMark
                                id={`${id}-${option.value}`}
                                value={option.value}
                                disabled={option.disabled}
                                focusRing={false}
                                className="mt-0.5"
                                aria-labelledby={`${id}-${option.value}-label`}
                                aria-describedby={`${id}-${option.value}-description`}
                            />
                            <span className="min-w-0 space-y-1">
                                <span
                                    id={`${id}-${option.value}-label`}
                                    className="flex flex-wrap items-center gap-2 text-sm font-medium"
                                >
                                    {option.label}
                                    {option.badge}
                                </span>
                                <span
                                    id={`${id}-${option.value}-description`}
                                    className={cn(
                                        'block text-xs leading-relaxed',
                                        isDisabled
                                            ? 'text-disabled-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {option.description}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </div>
        </RadioGroup>
    );
}
