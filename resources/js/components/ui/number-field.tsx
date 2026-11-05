import { Minus, Plus } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/**
 * A parsed number, `null` for an empty field, or the raw text the user typed or pasted when it is not a plain decimal number.
 * The raw text is never rewritten: callers pass it through to the server (which rejects it with its own message) or show the
 * shared "Enter a number." InputError and block the submit.
 */
export type NumberFieldValue = number | string | null;

const parse = (text: string): number | null => {
    const normalized = text.trim().replace(',', '.');
    return /^-?\d+(?:\.\d+)?$/.test(normalized) ? Number(normalized) : null;
};
const toValue = (text: string): NumberFieldValue =>
    text.trim() === '' ? null : (parse(text) ?? text);
const toText = (value: NumberFieldValue) =>
    value === null ? '' : String(value);

/**
 * Branded number entry: no browser spinners, the unit inside the field, −/+ steppers and ArrowUp/ArrowDown steps clamped to
 * min/max (stepping from an empty or unparsable field starts at min). Typed values are never clamped or rewritten silently.
 */
export function NumberField({
    id,
    name,
    value,
    onValueChange,
    min,
    max,
    step = 1,
    unit,
    placeholder,
    required,
    disabled,
    invalid,
    describedBy,
    className,
}: {
    id?: string;
    name?: string;
    value: NumberFieldValue;
    onValueChange: (value: NumberFieldValue) => void;
    min?: number;
    max?: number;
    step?: number;
    unit?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    className?: string;
}) {
    const { t } = useTranslation();
    const [text, setText] = useState(toText(value));
    useEffect(() => {
        setText((current) =>
            toValue(current) === value ? current : toText(value),
        );
    }, [value]);
    const number = typeof value === 'number' ? value : null;
    const unparsable = typeof value === 'string';
    const clamp = (next: number) =>
        Math.min(max ?? Infinity, Math.max(min ?? -Infinity, next));
    const stepBy = (direction: 1 | -1) => {
        // Round away binary noise from decimal steps (0.1 + 0.2).
        const next =
            number === null
                ? clamp(min ?? 0)
                : clamp(Number((number + direction * step).toFixed(6)));
        setText(String(next));
        onValueChange(next);
    };
    const stepButton = (direction: 1 | -1, label: string, icon: ReactNode) => (
        <button
            type="button"
            tabIndex={-1}
            aria-label={label}
            disabled={
                disabled ||
                (direction === -1
                    ? min !== undefined && number !== null && number <= min
                    : max !== undefined && number !== null && number >= max)
            }
            onClick={() => stepBy(direction)}
            className="flex h-full w-10 shrink-0 cursor-pointer items-center justify-center text-muted-foreground hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40 sm:w-7"
        >
            {icon}
        </button>
    );
    return (
        <div
            data-slot="number-field"
            aria-disabled={disabled || undefined}
            className={cn(
                'flex h-10 w-full min-w-0 items-stretch overflow-hidden rounded-md border border-input bg-muted transition-[color,box-shadow] has-focus-visible:ring-2 has-focus-visible:ring-ring has-aria-invalid:border-destructive aria-disabled:opacity-50 sm:h-8',
                className,
            )}
        >
            {stepButton(-1, t('Decrease'), <Minus className="size-3.5" />)}
            <input
                id={id}
                name={name}
                type="text"
                inputMode={Number.isInteger(step) ? 'numeric' : 'decimal'}
                autoComplete="off"
                role="spinbutton"
                aria-valuemin={min}
                aria-valuemax={max}
                aria-valuenow={number ?? undefined}
                aria-describedby={describedBy}
                aria-invalid={invalid || unparsable || undefined}
                aria-required={required || undefined}
                placeholder={placeholder}
                disabled={disabled}
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    onValueChange(toValue(event.target.value));
                }}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                        event.preventDefault();
                        stepBy(event.key === 'ArrowUp' ? 1 : -1);
                    }
                }}
                className="min-w-0 flex-1 bg-transparent px-1 text-center font-mono text-base tabular-nums outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed md:text-cell"
            />
            {unit && (
                <span className="flex shrink-0 items-center pr-1 text-xs text-muted-foreground">
                    {unit}
                </span>
            )}
            {stepButton(1, t('Increase'), <Plus className="size-3.5" />)}
        </div>
    );
}
