import type { ReactNode } from 'react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

export function SegmentedControl({
    value,
    onValueChange,
    options,
    label,
    disabled = false,
}: {
    value: string;
    onValueChange: (value: string) => void;
    options: { value: string; label: ReactNode }[];
    label: string;
    disabled?: boolean;
}) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(next) => {
                if (next) onValueChange(next);
            }}
            aria-label={label}
            disabled={disabled}
            spacing={1}
            className="h-10 max-w-full rounded-full border bg-muted p-1 sm:h-8 inactive:border-dashed"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.value}
                    value={option.value}
                    className="relative h-8 rounded-full px-3 text-xs leading-none text-muted-foreground before:absolute before:inset-x-0 before:top-1/2 before:h-10 before:-translate-y-1/2 hover:bg-ghost-hover hover:text-foreground active:bg-ghost-active active:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-foreground data-[state=on]:shadow-sm data-[state=on]:hover:bg-selected-surface-hover data-[state=on]:active:bg-selected-surface-active sm:h-6 sm:before:hidden inactive:bg-transparent inactive:text-disabled-ghost-foreground data-[state=on]:inactive:bg-disabled data-[state=on]:inactive:text-disabled-foreground data-[state=on]:inactive:shadow-none"
                >
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
