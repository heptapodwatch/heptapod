import { useTranslation } from 'react-i18next';
import { EvidenceFacts } from '@/components/evidence-facts';
import { AbsoluteTime } from '@/components/absolute-time';
import { useFormat } from '@/hooks/use-format';
import {
    checkSettingFact,
    checkSettingsSummary,
    effectiveSetting,
} from '@/lib/check-setting-facts';

/** Every effective setting of the check, read-only; shown at the top of its settings sheet. */
export function CheckSettingsFacts({
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

    return <EvidenceFacts rows={rows} />;
}

/** The schedule line in the check header: interval, next run and the check's key settings. */
export function CheckScheduleSummary({
    page,
}: {
    page: Heptapod.Data.Ui.CheckTabPageData;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    return (
        <span className="text-xs text-foreground-faint" data-check-schedule>
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
            {checkSettingsSummary(page.check_settings, t, format)
                .slice(1)
                .map((item) => (
                    <span key={item}>
                        {' · '}
                        {item}
                    </span>
                ))}
        </span>
    );
}
