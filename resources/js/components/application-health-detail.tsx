import { cn } from '@/lib/utils';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { listCardPadding } from '@/components/ui/table';
import { useTranslation } from 'react-i18next';
import { useFormat } from '@/hooks/use-format';
import { MonitoringTime, StatusBadge } from '@/components/monitoring';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { parseResult, resultState } from '@/lib/status';
import { SecurityAdvisoryList } from '@/components/security-advisory-list';

export function ApplicationHealthDetail({
    history,
}: {
    history: Heptapod.Addons.ApplicationHealth.ApplicationHealthDetailData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <div className="space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>{t('Application components')}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {history.stale && (
                        <Alert tone="warning">
                            <AlertTitle>
                                {t(
                                    'Component results are stale after a failed attempt',
                                )}
                            </AlertTitle>
                        </Alert>
                    )}
                    {history.last_valid_at && (
                        <p className="text-sm text-muted-foreground">
                            {t('Last accepted health result')} ·{' '}
                            <MonitoringTime value={history.last_valid_at} />
                        </p>
                    )}
                    {history.more_components && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('Showing first 50 components')}
                        </p>
                    )}
                    {history.components.length ? (
                        <ul className="divide-y rounded-lg border text-sm">
                            {history.components.map((component, index) => (
                                <li
                                    key={`${component.name}-${index}`}
                                    className={cn(
                                        'flex flex-wrap items-start gap-3',
                                        listCardPadding,
                                    )}
                                >
                                    <StatusBadge
                                        state={resultState(
                                            parseResult(
                                                component.latest_result,
                                            ),
                                        )}
                                    />
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <strong className="block break-words">
                                            {component.advisories
                                                ? t('Security advisories')
                                                : component.label ||
                                                  component.name}
                                        </strong>
                                        <p className="break-words text-muted-foreground">
                                            {component.advisories?.length
                                                ? t(
                                                      '{{count}} open advisories',
                                                      {
                                                          count: component
                                                              .advisories
                                                              .length,
                                                          formattedCount:
                                                              format.number(
                                                                  component
                                                                      .advisories
                                                                      .length,
                                                              ),
                                                      },
                                                  )
                                                : component.short_summary ||
                                                  component.status}
                                        </p>
                                    </div>
                                    {component.advisories?.length ? (
                                        <div className="basis-full">
                                            <SecurityAdvisoryList
                                                advisories={
                                                    component.advisories
                                                }
                                                more={history.more_advisories}
                                            />
                                        </div>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('No accepted components yet')}
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
