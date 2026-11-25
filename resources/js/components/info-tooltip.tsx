import { Info } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Detail behind a one-sentence description: a 16 px info button whose shared tooltip holds the rest. Hover and keyboard
 * focus open it as usual; a tap or click toggles it (touch has no hover), and Escape or a tap outside closes it.
 */
export function InfoTooltip({ children }: { children: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    // The state before this press: focus or hover may already have opened the tooltip by the time the click lands.
    const openBeforePress = useRef<boolean | null>(null);
    return (
        <Tooltip open={open} onOpenChange={setOpen}>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={t('More information')}
                    className="inline-flex size-5 shrink-0 items-center justify-center rounded-full align-[-0.3em] text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                    <Info className="size-4" aria-hidden />
                </button>
            </TooltipTrigger>
            <TooltipContent>{children}</TooltipContent>
        </Tooltip>
    );
}
