import { useTranslation } from 'react-i18next';
import { TextLink } from '@/components/text-link';
import { useFormat } from '@/hooks/use-format';
import { index as sitesIndex } from '@/routes/sites';

type Advisory = Heptapod.Addons.ApplicationHealth.SecurityAdvisoryData;

const severityLabels: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

/** The open advisories of a spatie security advisories result, grouped by package (the server sorts by package). */
export function SecurityAdvisoryList({
    advisories,
    more,
}: {
    advisories: Advisory[];
    more: boolean;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const packages = Map.groupBy(advisories, (advisory) => advisory.package);
    return (
        <div className="space-y-4">
            {[...packages].map(([name, items]) => (
                <section key={name} aria-label={name} className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p className="font-mono text-sm font-medium break-all">
                            {name}
                        </p>
                        <TextLink
                            variant="secondary"
                            href={sitesIndex.url({
                                query: { 'filter[package]': name },
                            })}
                            aria-label={t('Sites affected by {{name}}', {
                                name,
                            })}
                        >
                            {t('Sites affected')}
                        </TextLink>
                    </div>
                    <ul className="space-y-3">
                        {items.map((advisory, index) => (
                            <li
                                key={`${advisory.advisory_id}-${index}`}
                                className="space-y-0.5"
                            >
                                <TextLink external href={advisory.link}>
                                    {advisory.title}
                                </TextLink>
                                <p className="text-xs text-muted-foreground">
                                    {[
                                        advisory.cve,
                                        advisory.severity &&
                                            t('Severity: {{severity}}', {
                                                severity: Object.hasOwn(
                                                    severityLabels,
                                                    advisory.severity,
                                                )
                                                    ? t(
                                                          severityLabels[
                                                              advisory.severity
                                                          ] as string,
                                                      )
                                                    : advisory.severity,
                                            }),
                                        t('Affected versions: {{versions}}', {
                                            versions:
                                                advisory.affected_versions,
                                        }),
                                        t('Reported {{date}}', {
                                            date: format.calendarDate(
                                                advisory.reported_at,
                                            ),
                                        }),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    {' · '}
                                    <TextLink
                                        variant="secondary"
                                        href={sitesIndex.url({
                                            query: {
                                                'filter[advisory]':
                                                    advisory.advisory_id,
                                            },
                                        })}
                                        aria-label={t(
                                            'Sites affected by {{name}}',
                                            {
                                                name:
                                                    advisory.cve ??
                                                    advisory.title,
                                            },
                                        )}
                                    >
                                        {t('Sites affected')}
                                    </TextLink>
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
            {more && (
                <p role="status" className="text-sm text-muted-foreground">
                    {t('Showing first 100 advisories')}
                </p>
            )}
        </div>
    );
}
