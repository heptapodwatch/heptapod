import { cn } from '@/lib/utils';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { listCardPadding } from '@/components/ui/table';
import { useTranslation } from 'react-i18next';
import { ListFooter } from '@/components/list-footer';
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
                                            {component.name ===
                                            'SecurityAdvisories'
                                                ? t('Security advisories')
                                                : component.label ||
                                                  component.name}
                                        </strong>
                                        <p className="break-words text-muted-foreground">
                                            {component.name ===
                                                'SecurityAdvisories' &&
                                            history.advisories_pagination
                                                .total > 0
                                                ? t(
                                                      '{{count}} open advisories',
                                                      {
                                                          count: history
                                                              .advisories_pagination
                                                              .total,
                                                          formattedCount:
                                                              format.number(
                                                                  history
                                                                      .advisories_pagination
                                                                      .total,
                                                              ),
                                                      },
                                                  )
                                                : component.short_summary ||
                                                  component.status}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {history.components.length > 0 && (
                        <ListFooter
                            count={t('health.components_count', {
                                count: history.components_pagination.total,
                                formattedCount: format.number(
                                    history.components_pagination.total,
                                ),
                            })}
                            pagination={history.components_pagination}
                        />
                    )}
                    {history.components.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('No accepted components yet')}
                        </p>
                    )}
                </CardContent>
            </Card>
            {history.advisories.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Security advisories')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {t('{{count}} open advisories', {
                                count: history.advisories_pagination.total,
                                formattedCount: format.number(
                                    history.advisories_pagination.total,
                                ),
                            })}
                        </p>
                        <SecurityAdvisoryList
                            advisories={history.advisories}
                            pagination={history.advisories_pagination}
                        />
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
