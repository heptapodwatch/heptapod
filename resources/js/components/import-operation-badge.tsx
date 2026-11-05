import { StatusBadge } from '@/components/monitoring';
import { Badge } from '@/components/ui/badge';

export function ImportOperationBadge({
    operation,
    label,
}: {
    operation: Heptapod.Enums.ImportOperation;
    label: string;
}) {
    if (operation === 'failed' || operation === 'partial') {
        return (
            <StatusBadge
                state={operation === 'failed' ? 'failed' : 'warning'}
                label={label}
            />
        );
    }

    return <Badge variant="outline">{label}</Badge>;
}
