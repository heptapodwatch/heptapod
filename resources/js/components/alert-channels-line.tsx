import { Deferred } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { StatusDot } from '@/components/monitoring';
import { TextLink } from '@/components/text-link';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/hooks/use-format';
import { settings as siteSettings } from '@/routes/sites';

/**
 * "Who gets this" in one line on the site overview and the check tabs; the full explainer lives in
 * Site settings → Alerts. Only a count of 0 is a warning.
 */
export function AlertChannelsLine({
    siteId,
    checkId,
    category,
    count,
    canOpenSettings,
}: {
    siteId: number;
    checkId: number;
    category: 'outage' | 'failure';
    /** The deferred `alert_channels` prop: undefined while loading, null when the check never reports this category. */
    count: number | null | undefined;
    canOpenSettings: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <Deferred
            data="alert_channels"
            fallback={<Skeleton aria-busy="true" className="h-4 w-72" />}
        >
            {count === null || count === undefined ? null : (
                <p
                    data-alert-channels={count}
                    className="flex min-h-4 flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                >
                    <span aria-hidden className="inline-flex">
                        <StatusDot state={count === 0 ? 'warning' : 'ok'} />
                    </span>
                    {count === 0
                        ? t(
                              category === 'outage'
                                  ? 'alert_channels.outage_none'
                                  : 'alert_channels.failure_none',
                          )
                        : t(
                              category === 'outage'
                                  ? 'alert_channels.outage'
                                  : 'alert_channels.failure',
                              { count, formattedCount: format.number(count) },
                          )}
                    {canOpenSettings && <span aria-hidden>·</span>}
                    {canOpenSettings && (
                        <TextLink
                            variant="secondary"
                            // Laravel sorts the query; Inertia keeps #alerts only when the returned URL matches the requested one.
                            href={`${siteSettings.url(siteId, { query: { category, check: checkId } })}#alerts`}
                        >
                            {t('Details')}
                        </TextLink>
                    )}
                </p>
            )}
        </Deferred>
    );
}
