import { TooltipHint } from '@/components/truncated-text';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { useFormat } from '@/hooks/use-format';
import { useStreamState } from '@/hooks/use-live';

export function StreamLostStrip() {
    const { t } = useTranslation();
    const format = useFormat();
    const { status, dataFrom, dataReloadFailed } = useStreamState();
    if (status !== 'lost' && !dataReloadFailed) return null;

    return (
        <div
            role="status"
            className="flex h-8 shrink-0 items-center gap-2 border-b border-[var(--status-warn)]/25 bg-[var(--status-warn)]/8 px-4 text-xs text-[var(--status-warn)] md:px-6"
        >
            <span aria-hidden="true" className="inline-flex">
                <StatusDot state="warning" />
            </span>
            <TooltipHint content={format.exact(dataFrom)}>
                <span className="font-mono tabular-nums">
                    {t('Reconnecting · showing data from {{time}}', {
                        time: format.time(dataFrom),
                    })}
                </span>
            </TooltipHint>
        </div>
    );
}
