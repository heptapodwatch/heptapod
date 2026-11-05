import { TooltipHint } from '@/components/truncated-text';
import { useFormat } from '@/hooks/use-format';

export function PresentationChanges({
    changes,
    details = [],
}: {
    changes: Heptapod.Contracts.CheckRunPresentationPartData[];
    details?: Heptapod.Contracts.CheckRunPresentationPartData[];
}) {
    const format = useFormat();
    const parts = [...changes, ...details];
    if (parts.length === 0) return null;
    return (
        <TooltipHint content={format.presentationTime(parts)}>
            <span className="text-xs text-muted-foreground">
                {parts.map((part) => format.presentationPart(part)).join(' · ')}
            </span>
        </TooltipHint>
    );
}
