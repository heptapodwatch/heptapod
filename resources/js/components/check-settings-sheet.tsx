import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { router, useForm } from '@inertiajs/react';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { NumberField } from '@/components/ui/number-field';
import { Label } from '@/components/ui/label';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    SchemaFields,
    schemaFieldViews,
    addSiteFieldViews,
} from '@/components/schema-fields';
import { FormSecondaryAction } from '@/components/form-section';
import { Button } from '@/components/ui/button';
import { Sheet } from '@/components/ui/sheet';
import { checkSettingLabels } from '@/lib/check-types';
import { update as checkUpdate } from '@/routes/sites/checks';
import { probe as healthProbe } from '@/routes/sites/checks/health';
import { SheetForm } from '@/components/sheet-form';
import {
    SitemapUrlsPerRunField,
    sitemapUrlsPerRunField,
} from '@/components/sitemap-urls-per-run-field';
import type { FieldValue } from '@/types/monitoring';

type HealthProbeResult = {
    result: 'ok' | 'unauthorized' | 'invalid_format' | 'unreachable';
    component_count: number | null;
};

function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));
    if (!cookie) throw new Error('CSRF cookie missing for health settings');
    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

type DraftCheckSettingsProps = {
    check: Heptapod.Data.Ui.CheckOptionData;
    settings: Record<string, FieldValue>;
    errors: Record<string, string>;
    onClose: () => void;
    onApply: (settings: Record<string, FieldValue>) => void;
};

type SavedCheckSettingsProps = {
    check: Heptapod.Data.Ui.CheckSettingsData;
    /** Distinct listed sitemap URLs of the latest run; null when unknown. */
    discoveredUrls: number | null;
    /** Props to reload after a save; omitted, the whole page reloads. */
    reloadOnly?: string[];
    /** Accessible name of the trigger where several checks share one page. */
    triggerLabel?: string;
};

export function CheckSettingsSheet(
    props: SavedCheckSettingsProps | DraftCheckSettingsProps,
) {
    return 'discoveredUrls' in props ? (
        <SavedCheckSettingsSheet {...props} />
    ) : (
        <DraftCheckSettingsSheet {...props} />
    );
}

function DraftCheckSettingsSheet({
    check,
    settings,
    errors,
    onClose,
    onApply,
}: DraftCheckSettingsProps) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(settings);
    const urlsPerRunName = `${check.key}.${sitemapUrlsPerRunField}`;
    const defaults = Object.fromEntries(
        Object.entries(check.config_schema.fields)
            .filter(
                ([, field]) =>
                    field.default !== null && field.default !== undefined,
            )
            .map(([name, field]) => [
                `${check.key}.${name}`,
                field.default as FieldValue,
            ]),
    );
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <SheetForm
                title={t('Check settings')}
                description={t(check.label_key)}
                onSubmit={(event) => {
                    event.preventDefault();
                    onApply(draft);
                }}
                footer={
                    <>
                        <FormSecondaryAction onClick={onClose}>
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button type="submit">{t('Apply')}</Button>
                    </>
                }
            >
                <SchemaFields
                    schema={addSiteFieldViews(
                        check.config_schema,
                        check.key + '.',
                        checkSettingLabels,
                    ).filter((field) => field.name !== urlsPerRunName)}
                    values={{ ...defaults, ...draft }}
                    errors={errors}
                    onChange={(name, value) =>
                        setDraft((current) => ({ ...current, [name]: value }))
                    }
                />
                {check.key === 'sitemap' && (
                    <SitemapUrlsPerRunField
                        value={
                            draft[urlsPerRunName] ?? defaults[urlsPerRunName]
                        }
                        discoveredUrls={null}
                        intervalSeconds={check.interval_seconds}
                        error={errors[urlsPerRunName]}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                [urlsPerRunName]: value,
                            }))
                        }
                    />
                )}
            </SheetForm>
        </Sheet>
    );
}

function SavedCheckSettingsSheet({
    check,
    discoveredUrls,
    reloadOnly,
    triggerLabel,
}: SavedCheckSettingsProps) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const form = useForm<Record<string, FieldValue>>({});
    const intervalSeconds = check.interval_seconds;
    const intervalUnit =
        intervalSeconds % 86400 === 0
            ? 'day'
            : intervalSeconds % 3600 === 0
              ? 'hour'
              : 'minute';
    const intervalValue =
        intervalSeconds / { minute: 60, hour: 3600, day: 86400 }[intervalUnit];
    const scheduleValue = form.data.frequency_value ?? intervalValue;
    const scheduleUnit = form.data.frequency_unit ?? intervalUnit;
    if (
        typeof scheduleUnit !== 'string' ||
        (typeof scheduleValue !== 'string' && typeof scheduleValue !== 'number')
    ) {
        throw new Error('Invalid interval draft');
    }
    const changeSchedule = (value: string | number, unit: string) => {
        form.setData({
            ...form.data,
            frequency_value: value,
            frequency_unit: unit,
        });
    };
    const isHealth = check.check_key === 'application_health';
    const [probeResult, setProbeResult] = useState<HealthProbeResult | null>(
        null,
    );
    const [probeMessage, setProbeMessage] = useState<string | null>(null);
    const [probing, setProbing] = useState(false);
    const [saving, setSaving] = useState(false);
    const probeVersion = useRef(0);
    const isSitemap = check.check_key === 'sitemap';
    const draftIntervalSeconds =
        Number.isInteger(Number(scheduleValue)) &&
        Number(scheduleValue) >= 1 &&
        Number(scheduleValue) <= 60
            ? Number(scheduleValue) *
              ({ minute: 60, hour: 3600, day: 86400 }[scheduleUnit] ?? 0)
            : null;
    const fields = Object.entries(check.config_schema.fields).flatMap(
        ([name, field]) =>
            name === 'location' ||
            (isSitemap && name === sitemapUrlsPerRunField)
                ? []
                : schemaFieldViews(
                      {
                          fields:
                              field.type === 'object'
                                  ? field.properties
                                  : { [name]: field },
                      },
                      field.type === 'object' ? `${name}.` : '',
                      checkSettingLabels,
                  ),
    );
    const values = Object.fromEntries(
        Object.entries(check.config_schema.fields).flatMap(([name, field]) => {
            const value =
                (check.settings as Record<string, unknown>)[name] ??
                field.default;
            if (field.type === 'object') {
                const properties = (value ?? {}) as Record<string, unknown>;
                return Object.entries(field.properties).flatMap(
                    ([property, definition]) => {
                        const setting =
                            properties[property] ?? definition.default;
                        return setting === null || setting === undefined
                            ? []
                            : [[`${name}.${property}`, setting as FieldValue]];
                    },
                );
            }
            return value === null || value === undefined
                ? []
                : [[name, value as FieldValue]];
        }),
    );
    const errors = Object.fromEntries(
        Object.entries(form.errors).map(([path, message]) => [
            path.replace(/^values\./, '').replace(/\.\d+.*$/, ''),
            message,
        ]),
    );
    const editedValues: Record<string, FieldValue> = {};
    for (const [name, value] of Object.entries(form.data)) {
        if (
            value !== null &&
            !Array.isArray(value) &&
            typeof value === 'object'
        ) {
            for (const [property, setting] of Object.entries(value)) {
                editedValues[`${name}.${property}`] = setting;
            }
        } else {
            editedValues[name] = value;
        }
    }
    const testHealth = async () => {
        const version = probeVersion.current;
        setProbing(true);
        setProbeResult(null);
        setProbeMessage(null);
        try {
            const response = await fetch(
                healthProbe.url({ site: check.site_id, check: check.check_id }),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify(
                        Object.fromEntries(
                            Object.entries(form.data).filter(
                                ([key]) =>
                                    ![
                                        'frequency_value',
                                        'frequency_unit',
                                    ].includes(key),
                            ),
                        ),
                    ),
                },
            );
            if (response.status === 429) {
                if (version === probeVersion.current)
                    setProbeMessage(t('Health probe rate limit reached'));
                return;
            }
            if (response.status === 422) {
                const payload: { errors?: Record<string, string[]> } =
                    await response.json();
                if (version === probeVersion.current)
                    setProbeMessage(
                        t(
                            Object.keys(payload.errors ?? {}).some((key) =>
                                key.startsWith(
                                    'application_health_check_settings.',
                                ),
                            )
                                ? 'api.secrets_host_change'
                                : 'Invalid health settings',
                        ),
                    );
                return;
            }
            if (!response.ok)
                throw new Error(`Health probe failed: HTTP ${response.status}`);
            const result: HealthProbeResult = await response.json();
            if (
                ![
                    'ok',
                    'unauthorized',
                    'invalid_format',
                    'unreachable',
                ].includes(result.result)
            ) {
                throw new Error('Unexpected health probe response');
            }
            if (version === probeVersion.current) setProbeResult(result);
        } catch (error) {
            console.error(error);
            if (version === probeVersion.current)
                setProbeMessage(t('Health probe unavailable'));
        } finally {
            setProbing(false);
        }
    };
    const saveHealth = async () => {
        setSaving(true);
        setProbeMessage(null);
        try {
            const response = await fetch(
                checkUpdate.url({ site: check.site_id, check: check.check_id }),
                {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify(form.data),
                },
            );
            if (
                response.status === 429 ||
                response.status === 409 ||
                response.status === 422
            ) {
                setProbeResult(null);
                const payload: { errors?: Record<string, string[]> } | null =
                    response.status === 422 ? await response.json() : null;
                setProbeMessage(
                    t(
                        response.status === 429
                            ? 'Health probe rate limit reached'
                            : response.status === 409
                              ? 'checks.settings_changed'
                              : Object.keys(payload?.errors ?? {}).some((key) =>
                                      key.startsWith(
                                          'application_health_check_settings.',
                                      ),
                                  )
                                ? 'api.secrets_host_change'
                                : 'Invalid health settings',
                    ),
                );
                return;
            }
            if (!response.ok)
                throw new Error(
                    `Health settings save failed: HTTP ${response.status}`,
                );
            form.reset();
            setProbeResult(null);
            setOpen(false);
            router.reload(reloadOnly ? { only: reloadOnly } : {});
        } catch (error) {
            console.error(error);
            setProbeResult(null);
            setProbeMessage(t('Health probe unavailable'));
        } finally {
            setSaving(false);
        }
    };
    const submit = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        // React bubbles a portalled sheet's submit to an enclosing form (Site settings); this save is the check's own.
        event.stopPropagation();
        if (isHealth) {
            if (probeResult?.result === 'ok') void saveHealth();
            return;
        }
        form.patch(
            checkUpdate.url({ site: check.site_id, check: check.check_id }),
            {
                preserveScroll: true,
                ...(reloadOnly ? { only: reloadOnly } : {}),
                onSuccess: () => {
                    form.reset();
                    setProbeResult(null);
                    setOpen(false);
                },
                onError: () => setProbeResult(null),
            },
        );
    };

    const changeOpen = (value: boolean) => {
        setOpen(value);
        if (!value) {
            probeVersion.current++;
            form.reset();
            form.clearErrors();
            setProbeResult(null);
            setProbeMessage(null);
        }
    };
    return (
        <>
            <FormSecondaryAction
                size="sm"
                aria-label={triggerLabel}
                onClick={() => setOpen(true)}
            >
                {t('Configure')}
            </FormSecondaryAction>
            <Sheet open={open} onOpenChange={changeOpen}>
                <SheetForm
                    title={t(isHealth ? 'Edit health check' : 'Check settings')}
                    description={t(check.label_key)}
                    onSubmit={submit}
                    footer={
                        <>
                            <FormSecondaryAction
                                onClick={() => changeOpen(false)}
                            >
                                {t('Cancel')}
                            </FormSecondaryAction>
                            <Button
                                type="submit"
                                disabled={
                                    !form.isDirty ||
                                    form.processing ||
                                    probing ||
                                    saving ||
                                    (isHealth && probeResult?.result !== 'ok')
                                }
                            >
                                {t('Save')}
                            </Button>
                        </>
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="check-interval">
                                {t('Check interval')}
                            </Label>
                            <NumberField
                                id="check-interval"
                                min={1}
                                max={60}
                                required
                                invalid={!!errors.frequency_value}
                                describedBy="check-interval-error"
                                value={
                                    scheduleValue === '' ? null : scheduleValue
                                }
                                onValueChange={(value) =>
                                    changeSchedule(value ?? '', scheduleUnit)
                                }
                            />
                            <InputError
                                id="check-interval-error"
                                message={errors.frequency_value}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label id="check-interval-unit-label">
                                {t('Interval unit')}
                            </Label>
                            <RadioGroup
                                id="check-interval-unit"
                                aria-labelledby="check-interval-unit-label"
                                {...fieldErrorProps(
                                    'check-interval-unit',
                                    errors.frequency_unit,
                                )}
                                value={scheduleUnit}
                                onValueChange={(unit) =>
                                    changeSchedule(scheduleValue, unit)
                                }
                            >
                                <RadioGroupItem value="minute">
                                    {t('Minutes')}
                                </RadioGroupItem>
                                <RadioGroupItem value="hour">
                                    {t('Hours')}
                                </RadioGroupItem>
                                <RadioGroupItem value="day">
                                    {t('Days')}
                                </RadioGroupItem>
                            </RadioGroup>
                            <InputError
                                field="check-interval-unit"
                                message={errors.frequency_unit}
                            />
                        </div>
                    </div>
                    <SchemaFields
                        schema={fields}
                        values={{ ...values, ...editedValues }}
                        errors={errors}
                        onChange={(name, value) => {
                            probeVersion.current++;
                            setProbeResult(null);
                            setProbeMessage(null);
                            const dot = name.indexOf('.');
                            const parent = dot < 0 ? name : name.slice(0, dot);
                            const property =
                                dot < 0 ? null : name.slice(dot + 1);
                            const definition = property
                                ? check.config_schema.fields[parent]
                                      ?.properties[property]
                                : check.config_schema.fields[parent];
                            const setting =
                                value === '' &&
                                definition?.rules.includes('nullable')
                                    ? null
                                    : value;
                            if (!property) {
                                form.setData(name, setting);
                                return;
                            }
                            const existing = (form.data[parent] ??
                                (check.settings as Record<string, FieldValue>)[
                                    parent
                                ] ??
                                {}) as Record<
                                string,
                                string | number | boolean | null
                            >;
                            form.setData(parent, {
                                ...existing,
                                [property]: setting as
                                    | string
                                    | number
                                    | boolean
                                    | null,
                            });
                        }}
                    />
                    {isSitemap && (
                        <SitemapUrlsPerRunField
                            value={
                                editedValues[sitemapUrlsPerRunField] ??
                                values[sitemapUrlsPerRunField]
                            }
                            discoveredUrls={discoveredUrls}
                            intervalSeconds={draftIntervalSeconds}
                            error={errors[sitemapUrlsPerRunField]}
                            onChange={(value) =>
                                form.setData(sitemapUrlsPerRunField, value)
                            }
                        />
                    )}
                    {isHealth && (
                        <div className="space-y-3 text-sm">
                            <p className="text-muted-foreground">
                                {t(
                                    'Leave the secret unchanged to keep the stored value.',
                                )}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void testHealth()}
                                disabled={probing || saving || form.processing}
                            >
                                {t(
                                    probing
                                        ? 'Testing health endpoint…'
                                        : 'Test health endpoint',
                                )}
                            </Button>
                            {(probeResult || probeMessage) && (
                                <p
                                    role="status"
                                    aria-live="polite"
                                    className={
                                        probeResult?.result === 'ok'
                                            ? 'text-[var(--status-ok)]'
                                            : 'text-[var(--status-warn)]'
                                    }
                                >
                                    {probeMessage ??
                                        (probeResult?.result === 'ok'
                                            ? `${t('Health probe succeeded')} · ${probeResult.component_count} ${t('components')}`
                                            : t(
                                                  `health.probe.${probeResult?.result}`,
                                              ))}
                                </p>
                            )}
                            {probeResult?.result !== 'ok' && (
                                <p className="text-muted-foreground">
                                    {t(
                                        'Not saved yet. Test successfully before saving.',
                                    )}
                                </p>
                            )}
                        </div>
                    )}
                </SheetForm>
            </Sheet>
        </>
    );
}
