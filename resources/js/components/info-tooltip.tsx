import { Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/** Detail behind a one-sentence description: a 16 px info button whose shared tooltip holds the rest. */
export function InfoTooltip({ children }: { children: string }) {
    const { t } = useTranslation();
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={t('More information')}
                    className="inline-flex size-5 shrink-0 items-center justify-center rounded-full align-[-0.3em] text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Info className="size-4" aria-hidden />
                </button>
            </TooltipTrigger>
            <TooltipContent>{children}</TooltipContent>
        </Tooltip>
    );
}
