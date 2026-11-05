import { TooltipHint } from '@/components/truncated-text';
import { PresentationChanges } from '@/components/presentation-changes';
import { useFormat } from '@/hooks/use-format';

export function ResultSummary({
    message,
    params,
    presentation,
}: {
    message: string;
    params: Heptapod.Data.Ui.CheckStripData['summary_params'];
    presentation?: Heptapod.Contracts.CheckRunPresentationData | null;
}) {
    const format = useFormat();
    const summary = presentation
        ? format.presentation(presentation)
        : format.summary(message, params);

    return (
        <div className="min-w-0 break-words">
            <TooltipHint
                content={format.presentationTime(presentation?.parts ?? [])}
            >
                <span>{summary}</span>
            </TooltipHint>
            {presentation && (
                <div>
                    <PresentationChanges
                        changes={presentation.changes}
                        details={presentation.details}
                    />
                </div>
            )}
        </div>
    );
}
