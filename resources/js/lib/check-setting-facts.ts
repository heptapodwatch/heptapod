import type { TFunction } from 'i18next';
import type { useFormat } from '@/hooks/use-format';
import { checkSettingLabels } from '@/lib/check-types';
import type { CheckKey } from '@/types/monitoring';

type Format = ReturnType<typeof useFormat>;
export type CheckSettingFact = { label: string; value: string; mono: boolean };

/** The stored value of a setting, else its schema default. */
export function effectiveSetting(
    check: Heptapod.Data.Ui.CheckSettingsData,
    name: string,
): unknown {
    return (
        (check.settings as Record<string, unknown>)[name] ??
        check.config_schema.fields[name]?.default
    );
}

/** One configured setting as a translated label and display value; null when it has no value or is not shown. */
export function checkSettingFact(
    checkKey: CheckKey,
    name: string,
    field: Heptapod.Contracts.Schema.Field,
    value: unknown,
    t: TFunction,
    format: Format,
): CheckSettingFact | null {
    if (value === null || value === undefined || name === 'location')
        return null;
    const display =
        field.type === 'secret'
            ? '••••••••'
            : Array.isArray(value)
              ? value.length
                  ? value.every((item) => typeof item === 'string')
                      ? value
                            .map((item) => {
                                const options = field.presentation?.options;
                                if (
                                    !options ||
                                    Object.keys(options).length === 0
                                )
                                    return item;
                                const key = options[item];
                                if (!key)
                                    throw new Error(
                                        `Missing configuration option: ${name} ${item}`,
                                    );
                                return t(key);
                            })
                            .join(', ')
                      : JSON.stringify(value)
                  : t('None')
              : typeof value === 'object'
                ? JSON.stringify(value)
                : typeof value === 'boolean'
                  ? t(value ? 'Yes' : 'No')
                  : typeof value === 'number'
                    ? name.endsWith('_in_days')
                        ? t('certificate.days', { count: value })
                        : name.endsWith('_in_ms')
                          ? `${format.milliseconds(value)} ${t('ms')}`
                          : name.endsWith('_percentage')
                            ? `${format.number(value, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`
                            : format.number(value)
                    : typeof value === 'string'
                      ? field.presentation?.options[value]
                          ? t(field.presentation.options[value])
                          : value
                      : JSON.stringify(value);
    return {
        label: t(
            checkKey === 'certificate_health' &&
                name === 'expires_soon_threshold_in_days'
                ? 'Warn before expiry'
                : checkKey === 'performance' && name === 'change_percentage'
                  ? 'Warn above usual'
                  : (field.presentation?.labelKey ??
                    checkSettingLabels[name] ??
                    name),
        ),
        value: display,
        mono:
            field.type === 'secret' ||
            (typeof value !== 'boolean' && !name.endsWith('_in_days')),
    };
}

/** The settings a site settings row summarizes after the interval, per check type; types without an entry show the interval only. */
const summaryFields: Partial<Record<CheckKey, string[]>> = {
    uptime: ['http_verb'],
    performance: ['threshold_in_ms'],
    certificate_health: ['expires_soon_threshold_in_days'],
    domain: ['expires_soon_threshold_in_days'],
    sitemap: ['urls_per_run', 'speed'],
    broken_links: ['max_pages'],
    lighthouse: ['device_emulation'],
};

/** The compact summary of a stored check's key settings: its interval, then the type's summary fields with their effective values. */
export function checkSettingsSummary(
    check: Heptapod.Data.Ui.CheckSettingsData,
    t: TFunction,
    format: Format,
): string[] {
    return [
        format.intervalPhrase(check.interval_seconds),
        ...(summaryFields[check.check_key] ?? []).flatMap((name) => {
            const field = check.config_schema.fields[name];
            const fact = field
                ? checkSettingFact(
                      check.check_key,
                      name,
                      field,
                      effectiveSetting(check, name),
                      t,
                      format,
                  )
                : null;
            return fact
                ? [
                      t('checks.settings_summary.setting', {
                          label: fact.label,
                          value: fact.value,
                      }),
                  ]
                : [];
        }),
    ];
}
