import { useTranslation } from 'react-i18next';
import { TextLink } from '@/components/text-link';
import { useFormat } from '@/hooks/use-format';

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
        <div className="space-y-4 pt-2">
            {[...packages].map(([name, items]) => (
                <section key={name} aria-label={name} className="space-y-2">
                    <h4 className="font-mono text-sm font-medium break-all">
                        {name}
                    </h4>
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
                                                severity:
                                                    advisory.severity in
                                                    severityLabels
                                                        ? t(
                                                              severityLabels[
                                                                  advisory
                                                                      .severity
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
