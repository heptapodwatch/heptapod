import { usePage } from '@inertiajs/react';
import { useCallback, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
    FormSection,
} from '@/components/form-section';
import InputError from '@/components/input-error';
import type { SettingsPage } from '@/components/recipient-settings/model';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox';
import { TimeField } from '@/components/ui/date-time-field';
import { Label } from '@/components/ui/label';
import { WeekdayToggleGroup } from '@/components/ui/weekday-toggle-group';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { TextLink } from '@/components/text-link';
import { settings as clientSettings } from '@/routes/clients';
import { update } from '@/routes/notification-schedules';
import { edit as editProfile } from '@/routes/profile';
import { edit as editInstance } from '@/routes/settings/instance';

type Schedule = {
    quiet_start: string | null;
    quiet_end: string | null;
    quiet_weekends: boolean;
    digest_time: string;
    digest_weekdays: number[] | null;
};

const scheduleOf = (page: SettingsPage): Schedule => ({
    quiet_start: page.schedule.quiet_start,
    quiet_end: page.schedule.quiet_end,
    quiet_weekends: page.schedule.quiet_weekends,
    digest_time: page.schedule.digest_time,
    digest_weekdays: page.schedule.digest_weekdays,
});

/** The recipient's quiet hours and digest slot as a settings form: a draft with Discard/Save, like every settings section. */
export function ScheduleSection({
    page,
    onSaved,
}: {
    page: SettingsPage;
    onSaved: () => void;
}) {
    const { auth } = usePage().props;
    // Where the recipient's timezone is changed: instance settings (Owner only), the profile, or the client's settings.
    const timezoneSource =
        page.recipient_kind === 'team'
            ? auth.canManageInstance
                ? editInstance.url()
                : null
            : page.recipient_kind === 'user'
              ? editProfile.url()
              : clientSettings.url(page.recipient_id!);
    const { t } = useTranslation();
    const id = useId();
    const saved = scheduleOf(page);
    const form = useHttpRequest<Schedule>(saved);
    const [failure, setFailure] = useState('');
    const dirty = JSON.stringify(form.data) !== JSON.stringify(saved);
    // Typed text that is not a time keeps the previous value in the draft, so Save waits until every field parses.
    const [invalidFields, setInvalidFields] = useState<Set<string>>(new Set());
    const setInvalid = useCallback(
        (field: string, invalid: boolean) =>
            setInvalidFields((current) => {
                if (current.has(field) === invalid) return current;
                const next = new Set(current);
                if (invalid) next.add(field);
                else next.delete(field);
                return next;
            }),
        [],
    );
    const weekdays = form.data.digest_weekdays ?? [1, 2, 3, 4, 5, 6, 7];
    const noDigestDay = weekdays.length === 0;
    const blocked = invalidFields.size > 0 || noDigestDay;
    // Clearing one quiet-hours bound clears the other: quiet hours are both or none.
    const setQuiet = (key: 'quiet_start' | 'quiet_end', value: string) =>
        form.setData((current) => ({
            ...current,
            [key]: value === '' ? null : value,
            ...(value === '' ? { quiet_start: null, quiet_end: null } : {}),
        }));
    return (
        <form
            noValidate
            id={`${id}-schedule-form`}
            onSubmit={(event) => {
                event.preventDefault();
                if (!dirty || blocked || form.processing) return;
                setFailure('');
                void form
                    .patch(
                        update.url({
                            kind: page.recipient_kind,
                            ...(page.recipient_kind === 'client' &&
                            page.recipient_id !== null
                                ? { id: page.recipient_id }
                                : {}),
                        }),
                    )
                    .then(() => {
                        toast.success(t('recipient_settings.schedule.saved'));
                        onSaved();
                    })
                    .catch((error: unknown) => {
                        if (!(error instanceof HttpValidationError))
                            setFailure(t('recipient_settings.save_failed'));
                    });
            }}
        >
            <FormSection
                title={t('recipient_settings.schedule.title')}
                description={t(
                    page.recipient_kind === 'client'
                        ? 'recipient_settings.schedule.client_description'
                        : 'recipient_settings.schedule.description',
                )}
            >
                <p className="text-xs text-muted-foreground">
                    {t(
                        `recipient_settings.schedule.timezone_${page.recipient_kind}`,
                        {
                            timezone: page.schedule.timezone,
                        },
                    )}{' '}
                    {timezoneSource && (
                        <TextLink href={timezoneSource}>
                            {t('recipient_settings.schedule.timezone_change')}
                        </TextLink>
                    )}
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <ScheduleTimeField
                        id={`${id}-from`}
                        label={t('recipient_settings.schedule.quiet_from')}
                        value={form.data.quiet_start ?? ''}
                        serverError={form.errors.quiet_start}
                        onValueChange={(value) =>
                            setQuiet('quiet_start', value)
                        }
                        field="quiet_start"
                        onInvalidChange={setInvalid}
                    />
                    <ScheduleTimeField
                        id={`${id}-until`}
                        label={t('recipient_settings.schedule.quiet_until')}
                        value={form.data.quiet_end ?? ''}
                        serverError={form.errors.quiet_end}
                        onValueChange={(value) => setQuiet('quiet_end', value)}
                        field="quiet_end"
                        onInvalidChange={setInvalid}
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('recipient_settings.schedule.quiet_consequence')}
                </p>
                <CheckboxField
                    checked={form.data.quiet_weekends}
                    onCheckedChange={(checked) =>
                        form.setData('quiet_weekends', checked === true)
                    }
                    description={t(
                        'recipient_settings.schedule.weekends_description',
                    )}
                >
                    {t('recipient_settings.schedule.weekends')}
                </CheckboxField>
                <div className="grid gap-4 sm:grid-cols-2">
                    <ScheduleTimeField
                        id={`${id}-digest`}
                        label={t('recipient_settings.schedule.digest_time')}
                        value={form.data.digest_time}
                        serverError={form.errors.digest_time}
                        onValueChange={(value) =>
                            form.setData('digest_time', value)
                        }
                        field="digest_time"
                        onInvalidChange={setInvalid}
                    />
                    <div className="space-y-2">
                        <Label id={`${id}-days`}>
                            {t('recipient_settings.schedule.digest_days')}
                        </Label>
                        <WeekdayToggleGroup
                            numbering="iso"
                            labelledBy={`${id}-days`}
                            describedBy={`${id}-days-error`}
                            invalid={
                                noDigestDay || !!form.errors.digest_weekdays
                            }
                            value={weekdays}
                            onValueChange={(value) =>
                                form.setData(
                                    'digest_weekdays',
                                    value.length === 7 ? null : value,
                                )
                            }
                        />
                        <InputError
                            id={`${id}-days-error`}
                            message={
                                noDigestDay
                                    ? t('recipient_settings.schedule.no_days')
                                    : form.errors.digest_weekdays
                            }
                        />
                    </div>
                </div>
            </FormSection>
            <InputError message={failure} />
            <FormActions
                status={
                    <FormSaveStatus
                        dirty={dirty}
                        processing={form.processing}
                    />
                }
            >
                <FormSecondaryAction
                    disabled={!dirty || form.processing}
                    onClick={() => {
                        form.setData(saved);
                        form.clearErrors();
                    }}
                >
                    {t('Discard')}
                </FormSecondaryAction>
                <Button
                    type="submit"
                    form={`${id}-schedule-form`}
                    disabled={!dirty || blocked || form.processing}
                >
                    {t('Save')}
                </Button>
            </FormActions>
        </form>
    );
}

/** A labelled 24-hour time with its server error, or the format error while the typed text is not a time. */
function ScheduleTimeField({
    id,
    label,
    value,
    serverError,
    field,
    onValueChange,
    onInvalidChange,
}: {
    id: string;
    field: string;
    label: string;
    value: string;
    serverError?: string;
    onValueChange: (value: string) => void;
    onInvalidChange: (field: string, invalid: boolean) => void;
}) {
    const { t } = useTranslation();
    const [invalid, setInvalid] = useState(false);
    const report = useCallback(
        (next: boolean) => {
            setInvalid(next);
            onInvalidChange(field, next);
        },
        [field, onInvalidChange],
    );
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <TimeField
                id={id}
                value={value}
                invalid={!!serverError}
                describedBy={`${id}-error`}
                onValueChange={onValueChange}
                onInvalidChange={report}
            />
            <InputError
                id={`${id}-error`}
                message={
                    invalid
                        ? t('Enter the time in 24-hour format (HH:MM).')
                        : serverError
                }
            />
        </div>
    );
}
