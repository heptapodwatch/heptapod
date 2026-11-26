import { ToggleGroup as ToggleGroupPrimitive } from 'radix-ui';
import { useState, type ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type SegmentedOption = {
    value: string;
    label: ReactNode;
    /** Makes the option unavailable; its tooltip says why. */
    disabledReason?: string;
};

const itemClassName =
    "relative inline-flex h-8 w-auto min-w-0 shrink-0 items-center justify-center gap-2 rounded-full bg-transparent px-3 text-cell leading-none font-label whitespace-nowrap text-muted-foreground focus-ring press before:absolute before:inset-x-0 before:top-1/2 before:h-10 before:-translate-y-1/2 hover:bg-ghost-hover hover:text-foreground focus:z-10 focus-visible:z-10 active:bg-ghost-active active:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-foreground data-[state=on]:shadow-sm data-[state=on]:hover:bg-selected-surface-hover data-[state=on]:active:bg-selected-surface-active sm:h-6 sm:before:hidden inactive:pointer-events-none inactive:bg-transparent inactive:text-disabled-ghost-foreground data-[state=on]:inactive:bg-disabled data-[state=on]:inactive:text-disabled-foreground data-[state=on]:inactive:shadow-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4";

export function SegmentedControl({
    value,
    onValueChange,
    options,
    label,
    disabled = false,
}: {
    value: string;
    onValueChange: (value: string) => void;
    options: SegmentedOption[];
    label: string;
    disabled?: boolean;
}) {
    return (
        <ToggleGroupPrimitive.Root
            data-slot="toggle-group"
            type="single"
            value={value}
            onValueChange={(next) => {
                const option = options.find((item) => item.value === next);
                if (option && !option.disabledReason) onValueChange(next);
            }}
            aria-label={label}
            disabled={disabled}
            className="flex h-10 w-fit max-w-full items-center gap-1 rounded-full border bg-muted p-1 sm:h-8 inactive:border-dashed"
        >
            {options.map((option) =>
                option.disabledReason ? (
                    <DisabledOption key={option.value} option={option} />
                ) : (
                    <ToggleGroupPrimitive.Item
                        key={option.value}
                        data-slot="toggle-group-item"
                        value={option.value}
                        className={itemClassName}
                    >
                        {option.label}
                    </ToggleGroupPrimitive.Item>
                ),
            )}
        </ToggleGroupPrimitive.Root>
    );
}

/**
 * Stays focusable (aria-disabled, not disabled) so keyboard focus and hover can reach its reason; a tap opens the reason
 * too, since touch has no hover.
 */
function DisabledOption({ option }: { option: SegmentedOption }) {
    const [open, setOpen] = useState(false);
    return (
        <Tooltip open={open} onOpenChange={setOpen}>
            <TooltipTrigger asChild onClick={() => setOpen(true)}>
                <ToggleGroupPrimitive.Item
                    data-slot="toggle-group-item"
                    value={option.value}
                    aria-disabled="true"
                    className={cn(
                        itemClassName,
                        'inactive:pointer-events-auto',
                    )}
                >
                    {option.label}
                </ToggleGroupPrimitive.Item>
            </TooltipTrigger>
            <TooltipContent>{option.disabledReason}</TooltipContent>
        </Tooltip>
    );
}
