import { useFormat } from '@/hooks/use-format';
import { listRowPadding } from '@/components/ui/table';
import { useTranslation } from 'react-i18next';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { TasksApiNotice } from '@/components/tasks-api-notice';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { addSiteFieldViews } from '@/components/schema-fields';
import { checkIcon, checkSettingLabels } from '@/lib/check-types';
import { eligibleSiteCheck } from '@/lib/site-create-draft';
import type { CheckKey, FieldValue } from '@/types/monitoring';

const displayOrder: CheckKey[] = [
    'uptime',
    'performance',
    'certificate_health',
    'domain',
    'dns',
    'sitemap',
    'broken_links',
    'mixed_content',
    'lighthouse',
    'application_health',
    'cron',
];

const groupLabels = {
    recommended: 'add_site.group.recommended',
    optional: 'add_site.group.optional',
    later: 'add_site.group.later',
    address: 'add_site.group.address',
    unavailable: 'add_site.group.unavailable',
};

export function SiteChecksList({
    checks,
    url,
    recommended,
    selected,
    settings,
    canManage,
    onToggle,
    onSettings,
    groupProps,
}: {
    /** The group's name and its error link (fieldErrorProps): the switches are one field for validation. */
    groupProps: {
        'aria-label': string;
        'aria-invalid': true | undefined;
        'aria-describedby': string | undefined;
    };
    checks: Heptapod.Data.Ui.CheckOptionData[];
    url: string;
    recommended: CheckKey[];
    selected: CheckKey[];
    settings: Record<string, FieldValue>;
    canManage: boolean;
    onToggle: (key: CheckKey, enabled: boolean) => void;
    onSettings: (key: CheckKey) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const group = (check: Heptapod.Data.Ui.CheckOptionData) =>
        check.key === 'cron'
            ? 'later'
            : check.unavailable_reason
              ? 'unavailable'
              : !eligibleSiteCheck(check, url)
                ? 'address'
                : recommended.includes(check.key)
                  ? 'recommended'
                  : 'optional';
    return (
        <div
            role="group"
            id="site-checks"
            {...groupProps}
            className="space-y-6"
        >
            {(Object.keys(groupLabels) as (keyof typeof groupLabels)[]).map(
                (section) => {
                    const rows = checks
                        .filter((check) => group(check) === section)
                        .sort(
                            (left, right) =>
                                displayOrder.indexOf(left.key) -
                                displayOrder.indexOf(right.key),
                        );
                    if (!rows.length) return null;
                    return (
                        <section
                            key={section}
                            aria-label={t(groupLabels[section])}
                            className="space-y-2"
                        >
                            <SectionHeading level={3}>
                                {t(groupLabels[section])}
                            </SectionHeading>
                            <div
                                className={
                                    section === 'unavailable' ||
                                    section === 'address'
                                        ? 'divide-y divide-dashed rounded-lg border border-dashed'
                                        : 'divide-y rounded-lg border bg-card'
                                }
                            >
                                {rows.map((check) => {
                                    const Icon = checkIcon(check.icon);
                                    const selectable =
                                        section === 'recommended' ||
                                        section === 'optional';
                                    const blocked =
                                        check.key === 'performance' &&
                                        !selected.includes('uptime');
                                    const fields = addSiteFieldViews(
                                        check.config_schema,
                                        check.key + '.',
                                        checkSettingLabels,
                                    );
                                    const missing = fields.some((field) => {
                                        const value =
                                            settings[field.name] ??
                                            check.config_schema.fields[
                                                field.name.slice(
                                                    check.key.length + 1,
                                                )
                                            ]?.default;
                                        return (
                                            field.required &&
                                            (value === undefined ||
                                                value === null ||
                                                value === '')
                                        );
                                    });
                                    const changed = Object.entries(
                                        check.config_schema.fields,
                                    ).filter(([name, field]) => {
                                        const value =
                                            settings[`${check.key}.${name}`];
                                        return (
                                            value !== undefined &&
                                            JSON.stringify(value) !==
                                                JSON.stringify(field.default)
                                        );
                                    }).length;
                                    const id = `check-${check.key}`;
                                    return (
                                        <div
                                            key={check.key}
                                            className={listRowPadding}
                                        >
                                            <div className="flex items-start gap-3">
                                                <Icon
                                                    aria-hidden="true"
                                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                                />
                                                <div className="min-w-0 flex-1 space-y-1">
                                                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                                        {selectable ? (
                                                            <label
                                                                htmlFor={id}
                                                                className="cursor-pointer text-sm font-medium"
                                                            >
                                                                {t(
                                                                    check.label_key,
                                                                )}
                                                            </label>
                                                        ) : (
                                                            <p className="text-sm font-medium">
                                                                {t(
                                                                    check.label_key,
                                                                )}
                                                            </p>
                                                        )}
                                                        {selectable && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {format.intervalPhrase(
                                                                    check.interval_seconds,
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            `add_site.purpose.${check.key}`,
                                                        )}
                                                    </p>
                                                    {blocked && (
                                                        <p className="text-xs text-[var(--status-warn)]">
                                                            {t(
                                                                'add_site.blocked.uptime',
                                                            )}
                                                        </p>
                                                    )}
                                                    {section === 'later' && (
                                                        <TasksApiNotice
                                                            canManage={
                                                                canManage
                                                            }
                                                        />
                                                    )}
                                                    {section ===
                                                        'unavailable' && (
                                                        <div className="space-y-1 text-xs text-muted-foreground">
                                                            <p>
                                                                {t(
                                                                    check.unavailable_reason!,
                                                                )}
                                                            </p>
                                                            {check.key ===
                                                                'lighthouse' && (
                                                                <p>
                                                                    {t(
                                                                        'add_site.unavailable.browser_hint',
                                                                    )}{' '}
                                                                    <InfoTooltip>
                                                                        {t(
                                                                            'add_site.unavailable.browser_detail',
                                                                        )}
                                                                    </InfoTooltip>
                                                                </p>
                                                            )}
                                                        </div>
                                                    )}
                                                    {section === 'address' && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {t(
                                                                'add_site.unavailable.https_hint',
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                {selectable && (
                                                    <Switch
                                                        id={id}
                                                        checked={
                                                            !blocked &&
                                                            selected.includes(
                                                                check.key,
                                                            )
                                                        }
                                                        disabled={blocked}
                                                        onCheckedChange={(
                                                            enabled,
                                                        ) =>
                                                            onToggle(
                                                                check.key,
                                                                enabled,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </div>
                                            {selectable &&
                                                fields.length > 0 && (
                                                    <div className="mt-2 flex flex-wrap items-center gap-2 pl-7">
                                                        <Button
                                                            type="button"
                                                            size="xs"
                                                            variant="outline"
                                                            onClick={() =>
                                                                onSettings(
                                                                    check.key,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'add_site.settings.for_check',
                                                                {
                                                                    check: t(
                                                                        check.label_key,
                                                                    ),
                                                                },
                                                            )}
                                                        >
                                                            {t('Settings')}
                                                        </Button>
                                                        <span
                                                            className={
                                                                missing
                                                                    ? 'text-xs text-[var(--status-warn)]'
                                                                    : 'text-xs text-muted-foreground'
                                                            }
                                                        >
                                                            {missing
                                                                ? t(
                                                                      'add_site.settings.needs_setup',
                                                                  )
                                                                : changed > 0
                                                                  ? t(
                                                                        'add_site.settings.changed',
                                                                        {
                                                                            count: changed,
                                                                        },
                                                                    )
                                                                  : t(
                                                                        'add_site.settings.defaults',
                                                                    )}
                                                        </span>
                                                    </div>
                                                )}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    );
                },
            )}
        </div>
    );
}
