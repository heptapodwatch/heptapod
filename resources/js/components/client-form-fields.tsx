import { TooltipHint } from '@/components/truncated-text';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { formatDateTime } from '@/lib/date-time';
import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/form-section';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { TimezoneCombobox } from '@/components/timezone-combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ClientFormValues = {
    name: string;
    short_name: string;
    contact_name: string;
    locale: string;
    timezone: string;
};

export function clientFormValues(
    client: Heptapod.Data.Ui.ClientData | null = null,
): ClientFormValues {
    return {
        name: client?.name ?? '',
        short_name: client?.short_name ?? '',
        contact_name: client?.contact_name ?? '',
        locale: client?.locale ?? 'en',
        timezone: client?.timezone ?? 'UTC',
    };
}

export function ClientFormFields(form: {
    data: ClientFormValues;
    errors: Partial<Record<keyof ClientFormValues, string>>;
    setData: (name: keyof ClientFormValues, value: string) => void;
    /** Server IANA timezone list. */
    timezones: string[];
}) {
    const { t } = useTranslation();
    const validTimezone = form.timezones.includes(form.data.timezone);
    return (
        <>
            <FormSection
                title={t('Client identity')}
                description={t('Name and contact details for this client.')}
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
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
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
                    <div className="space-y-2">
                        <Label htmlFor="contact-name">
                            {t('Contact name')}
                        </Label>
                        <Input
                            id="contact-name"
                            {...fieldErrorProps(
                                'contact-name',
                                form.errors.contact_name,
                            )}
                            name="contact_name"
                            value={form.data.contact_name}
                            onChange={(event) =>
                                form.setData('contact_name', event.target.value)
                            }
                        />
                        <InputError
                            field="contact-name"
                            message={form.errors.contact_name}
                        />
                    </div>
                </div>
            </FormSection>
            <FormSection
                title={t('Language and time')}
                description={t('Language and timezone for this client.')}
            >
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label id="client-locale-label">{t('Language')}</Label>
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
                        <Label htmlFor="client-timezone">{t('Timezone')}</Label>
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
                            onValueChange={(value) =>
                                form.setData('timezone', value)
                            }
                            pinned={['UTC']}
                        />
                        <InputError
                            field="client-timezone"
                            message={form.errors.timezone}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('Preview')}:{' '}
                        {validTimezone ? (
                            <TooltipHint
                                content={formatDateTime(
                                    '2026-11-08T19:00:00Z',
                                    form.data.locale,
                                    form.data.timezone,
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
                                        form.data.timezone,
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
