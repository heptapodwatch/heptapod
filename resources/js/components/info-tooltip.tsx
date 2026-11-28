import { Info } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * A button whose tooltip opens on hover and keyboard focus as usual; a tap or click toggles it (touch has no hover), and
 * Escape or a tap outside closes it. `label` is the button's accessible name.
 */
export function TapTooltip({
    content,
    label,
    className,
    children,
}: {
    content: ReactNode;
    label: string;
    className?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    // The state before this press: focus or hover may already have opened the tooltip by the time the click lands.
    const openBeforePress = useRef<boolean | null>(null);
    return (
        <Tooltip open={open} onOpenChange={setOpen}>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    className={cn(
                        'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        className,
                    )}
                    onPointerDown={(event) => {
                        // Skips Radix's close-on-press; a mouse press keeps a hover-opened tooltip open.
                        event.preventDefault();
                        openBeforePress.current =
                            event.pointerType === 'mouse' ? false : open;
                    }}
                    onClick={(event) => {
                        event.preventDefault();
                        const before = openBeforePress.current ?? open;
                        openBeforePress.current = null;
                        setOpen(!before);
                    }}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{content}</TooltipContent>
        </Tooltip>
    );
}

/** Detail behind a one-sentence description: a 16 px info button whose shared tooltip holds the rest. */
export function InfoTooltip({ children }: { children: string }) {
    const { t } = useTranslation();
    return (
        <TapTooltip
            content={children}
            label={t('More information')}
            className="inline-flex size-5 shrink-0 items-center justify-center rounded-full align-[-0.3em] text-muted-foreground hover:text-foreground"
        >
            <Info className="size-4" aria-hidden />
        </TapTooltip>
    );
}
