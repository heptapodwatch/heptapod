import { TooltipHint } from '@/components/truncated-text';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { formatDateTime } from '@/lib/date-time';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/form-section';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { TimezoneCombobox } from '@/components/timezone-combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ClientFormValues = {
    name: string;
    short_name: string;
    locale: string;
    /** Null follows the instance zone. */
    timezone: string | null;
};

export function clientFormValues(
    client: Heptapod.Data.Ui.ClientData | null = null,
): ClientFormValues {
    return {
        name: client?.name ?? '',
        short_name: client?.short_name ?? '',
        locale: client?.locale ?? 'en',
        timezone: client?.timezone_override ?? null,
    };
}

export function ClientFormFields(form: {
    data: ClientFormValues;
    errors: Partial<Record<keyof ClientFormValues, string>>;
    setData: <K extends keyof ClientFormValues>(
        name: K,
        value: ClientFormValues[K],
    ) => void;
    /** Server IANA timezone list. */
    timezones: string[];
    internal?: boolean;
}) {
    const { t } = useTranslation();
    const { instanceTimezone } = usePage().props;
    const timezone = form.data.timezone ?? instanceTimezone;
    const validTimezone = form.timezones.includes(timezone);
    return (
        <>
            <FormSection
                title={t('Client identity')}
                description={t('Name and short name for this client.')}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="client-name">{t('Client name')}</Label>
                        <Input
                            id="client-name"
                            {...fieldErrorProps(
                                'client-name',
                                form.errors.name,
                            )}
                            name="name"
                            required
                            readOnly={form.internal}
                            value={
                                form.internal ? t('No client') : form.data.name
                            }
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.internal && (
                            <p className="text-xs text-muted-foreground">
                                {t('Sites without a client are grouped here.')}
                            </p>
                        )}
                        <InputError
                            field="client-name"
                            message={form.errors.name}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="client-short-name">
                            {t('Short name')}
                        </Label>
                        <Input
                            id="client-short-name"
                            {...fieldErrorProps(
                                'client-short-name',
                                form.errors.short_name,
                            )}
                            name="short_name"
                            value={form.data.short_name}
                            onChange={(event) =>
                                form.setData('short_name', event.target.value)
                            }
                        />
                        <InputError
                            field="client-short-name"
                            message={form.errors.short_name}
                        />
                    </div>
                </div>
            </FormSection>
            <FormSection
                title={t('Language and time')}
                description={t('Language and timezone for this client.')}
            >
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)]">
                        <div className="space-y-2">
                            <Label id="client-locale-label">
                                {t('Language')}
                            </Label>
                            <RadioGroup
                                id="client-locale"
                                aria-labelledby="client-locale-label"
                                {...fieldErrorProps(
                                    'client-locale',
                                    form.errors.locale,
                                )}
                                name="locale"
                                value={String(form.data.locale)}
                                onValueChange={(value) =>
                                    form.setData('locale', value)
                                }
                            >
                                <RadioGroupItem value={String('en')}>
                                    {t('English')}
                                </RadioGroupItem>
                                <RadioGroupItem value={String('de')}>
                                    {t('German')}
                                </RadioGroupItem>
                            </RadioGroup>
                            <InputError
                                field="client-locale"
                                message={form.errors.locale}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="client-timezone">
                                {t('Timezone')}
                            </Label>
                            <TimezoneCombobox
                                id="client-timezone"
                                invalid={!!form.errors.timezone}
                                describedBy={
                                    fieldErrorProps(
                                        'client-timezone',
                                        form.errors.timezone,
                                    )['aria-describedby']
                                }
                                timezones={form.timezones}
                                value={form.data.timezone}
                                onValueChange={(value: string | null) =>
                                    form.setData('timezone', value)
                                }
                                defaultZone={{
                                    zone: instanceTimezone,
                                    source: t('timezone.source.instance'),
                                }}
                            />
                            <InputError
                                field="client-timezone"
                                message={form.errors.timezone}
                            />
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('Preview')}:{' '}
                        {validTimezone ? (
                            <TooltipHint
                                content={formatDateTime(
                                    '2026-11-08T19:00:00Z',
                                    form.data.locale,
                                    timezone,
                                    'exact',
                                )}
                            >
                                <time
                                    className="font-mono tabular-nums"
                                    dateTime="2026-11-08T19:00:00Z"
                                >
                                    {formatDateTime(
                                        '2026-11-08T19:00:00Z',
                                        form.data.locale,
                                        timezone,
                                        'timestamp',
                                    )}
                                </time>
                            </TooltipHint>
                        ) : (
                            '—'
                        )}
                    </p>
                </div>
            </FormSection>
        </>
    );
}
