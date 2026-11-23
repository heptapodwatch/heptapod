import { useTranslation } from 'react-i18next';
import { EvidenceFacts } from '@/components/evidence-facts';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { AbsoluteTime } from '@/components/absolute-time';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    CardAction,
} from '@/components/ui/card';
import { Disclosure } from '@/components/ui/disclosure';
import { useFormat } from '@/hooks/use-format';
import {
    checkSettingFact,
    checkSettingsSummary,
    effectiveSetting,
} from '@/lib/check-setting-facts';

export function CheckConfiguration({
    page,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const certificate = page.check.key === 'certificate_health';
    const host = new URL(page.header.url);
    const check = page.check_settings;
    const effectiveNumber = (name: string): number => {
        const value = effectiveSetting(check, name);
        if (typeof value !== 'number') {
            throw new Error(`Missing numeric configuration: ${name}`);
        }
        return value;
    };
    const settings = Object.entries(check.config_schema.fields).flatMap(
        ([name, field]) => {
            const fact = checkSettingFact(
                check.check_key,
                name,
                field,
                effectiveSetting(check, name),
                t,
                format,
            );
            if (!fact) return [];
            return [
                fact,
                ...(check.check_key === 'performance' &&
                name === 'threshold_in_ms'
                    ? [
                          {
                              label: t('Recovers below'),
                              value: `${
                                  format.summaryParams({
                                      recovery_below:
                                          (effectiveNumber('threshold_in_ms') *
                                              (100 -
                                                  effectiveNumber(
                                                      'recovery_margin_percentage',
                                                  ))) /
                                          100,
                                  }).recovery_below
                              } ${t('ms')}`,
                              mono: true,
                          },
                      ]
                    : []),
            ];
        },
    );

    const summaryHighlights = checkSettingsSummary(check, t, format).slice(1);
    const rows = [
        {
            label: t(certificate ? 'Host' : 'URL'),
            value: certificate
                ? `${host.hostname}:${host.port || '443'}`
                : page.header.url,
            mono: true,
        },
        {
            label: t('Schedule'),
            value: format.intervalPhrase(page.schedule.interval_seconds),
        },
        ...(page.check.key === 'performance'
            ? [
                  {
                      label: t('Usual'),
                      value: t('7-day median for the same UTC hour'),
                  },
                  {
                      label: t('Minimum difference from normal'),
                      value: `${format.milliseconds(
                          page.performance_noise?.minimum_increase_ms ??
                              (() => {
                                  throw new Error(
                                      'Performance needs its minimum increase.',
                                  );
                              })(),
                      )} ${t('ms')}`,
                      mono: true,
                  },
              ]
            : []),
        ...settings,
        ...(page.check.enabled && page.schedule.next_due_at
            ? [
                  {
                      label: t('Next run'),
                      value: (
                          <AbsoluteTime
                              value={page.schedule.next_due_at}
                              className="font-mono text-xs"
                          />
                      ),
                  },
              ]
            : []),
    ];

    return (
        <Card className="overflow-hidden" data-check-configuration>
            <CardHeader>
                <CardTitle>{t('Check settings')}</CardTitle>
                <CardDescription className="text-xs leading-relaxed">
                    {format.intervalPhrase(page.schedule.interval_seconds)}
                    {page.check.enabled && page.schedule.next_due_at && (
                        <>
                            {' · '}
                            {t('Next run')}{' '}
                            <AbsoluteTime
                                value={page.schedule.next_due_at}
                                className="font-mono text-xs"
                            />
                        </>
                    )}
                    {summaryHighlights.map((item) => (
                        <span key={item}>
                            {' · '}
                            {item}
                        </span>
                    ))}
                </CardDescription>
                {check.can_update && (
                    <CardAction>
                        <CheckSettingsSheet
                            check={check}
                            discoveredUrls={
                                page.detail?.check_type === 'sitemap'
                                    ? page.detail.totalUrlCount
                                    : null
                            }
                        />
                    </CardAction>
                )}
            </CardHeader>
            {check.attention_reason && (
                <CardContent className="border-b">
                    <Alert tone="warning">
                        <AlertTitle>{t('Needs attention')}</AlertTitle>
                        <AlertDescription>
                            {t(check.attention_reason)}
                        </AlertDescription>
                    </Alert>
                </CardContent>
            )}
            <Disclosure
                chevron="end"
                triggerClassName="px-4 py-2.5 text-xs text-muted-foreground hover:text-foreground focus-visible:ring-inset"
                summary={<span>{t('site_sections.show_all_settings')}</span>}
            >
                <div className="border-t px-4 py-4">
                    <EvidenceFacts rows={rows} />
                </div>
            </Disclosure>
        </Card>
    );
}
