import { Button } from '@/components/ui/button';
import { useTranslation } from 'react-i18next';

export function PendingChanges({
    count,
    apply,
}: {
    count: number;
    apply: () => void;
}) {
    const { t } = useTranslation();
    if (count === 0) return null;

    return (
        <Button type="button" variant="outline" size="sm" onClick={apply}>
            {t(
                count === 1
                    ? '{{count}} new change ↑'
                    : '{{count}} new changes ↑',
                { count },
            )}
        </Button>
    );
}
