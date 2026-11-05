import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { TextLink } from '@/components/text-link';
import { Alert, AlertTitle } from '@/components/ui/alert';

/**
 * The shared monitoring-health causes (dispatcher, system issues, unavailable or late checks) as PageHeader notices:
 * one line each with the server-chosen action. The sidebar health pill stays the summary.
 */
export function MonitoringHealthNotices() {
    const { t } = useTranslation();
    const causes = usePage().props.overallHealth?.causes ?? [];
    return causes.map((cause) => (
        <Alert
            key={cause.title_key}
            tone="warning"
            action={
                <TextLink document href={cause.href}>
                    {t(cause.action_key)}
                </TextLink>
            }
        >
            <AlertTitle>{t(cause.title_key)}</AlertTitle>
        </Alert>
    ));
}
