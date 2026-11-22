import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export function EvidenceBlock({
    title,
    rows,
    wrap = false,
}: {
    title: string;
    rows: { label: string; value: string; exact?: string }[];
    wrap?: boolean;
}) {
    const valueText = (value: string) => (
        <span
            className={cn(
                'block font-mono text-xs',
                wrap ? 'break-words' : 'truncate',
            )}
        >
            {value}
        </span>
    );
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-2 text-sm">
                    {rows.map(({ label, value, exact }) => (
                        <div
                            key={label}
                            className="grid grid-cols-[8rem_minmax(0,1fr)] gap-3"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="min-w-0">
                                {exact ? (
                                    <TooltipHint content={exact}>
                                        {valueText(value)}
                                    </TooltipHint>
                                ) : (
                                    <TruncatedText content={value}>
                                        {valueText(value)}
                                    </TruncatedText>
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}
