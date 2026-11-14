import { EASE_OUT } from '@/lib/motion';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { ArrowRight } from 'lucide-react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import {
    NumberField,
    type NumberFieldValue,
} from '@/components/ui/number-field';
import { Label } from '@/components/ui/label';
import { CheckboxField } from '@/components/ui/checkbox';
import { RadioCards } from '@/components/ui/radio-cards';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export type Sensitivity = Heptapod.Data.Ui.AlertSensitivityData;
export type SensitivityBounds =
    Heptapod.Data.Ui.AlertSensitivitySettingsPageData['bounds'];
/** The four settings saved together; all null at site level means "inherit the team default". */
export type SensitivityDraft = {
    alert_after_minutes: number | null;
    alert_recovery_checks: number | null;
    alert_confirms_other_checks: boolean | null;
    alert_reminders: Heptapod.Enums.AlertReminders | null;
};
export type SensitivityChoice =
    | NonNullable<Sensitivity['preset']>
    | 'custom'
    | 'inherit';

export const inheritedDraft: SensitivityDraft = {
    alert_after_minutes: null,
    alert_recovery_checks: null,
    alert_confirms_other_checks: null,
    alert_reminders: null,
};

export function draftOf(value: Sensitivity): SensitivityDraft {
    return {
        alert_after_minutes: value.alert_after_minutes,
        alert_recovery_checks: value.alert_recovery_checks,
        alert_confirms_other_checks: value.alert_confirms_other_checks,
        alert_reminders: value.alert_reminders,
    };
}

/** The preset control is derived from the values: the preset they match, otherwise Custom. */
export function sensitivityChoice(
    value: SensitivityDraft,
    presets: Sensitivity[],
): SensitivityChoice {
    if (value.alert_reminders === null) return 'inherit';
    return (
        presets.find(
            (preset) =>
                preset.alert_after_minutes === value.alert_after_minutes &&
                preset.alert_recovery_checks === value.alert_recovery_checks &&
                preset.alert_confirms_other_checks ===
                    value.alert_confirms_other_checks &&
                preset.alert_reminders === value.alert_reminders,
        )?.preset ?? 'custom'
    );
}

export function validSensitivity(
    value: SensitivityDraft,
    bounds: SensitivityBounds,
    savedMinutes?: number,
): boolean {
    const minutes = value.alert_after_minutes;
    const checks = value.alert_recovery_checks;
    return (
        minutes !== null &&
        Number.isInteger(minutes) &&
        minutes >= bounds.stored_minutes_min &&
        minutes <= bounds.stored_minutes_max &&
        ((minutes >= bounds.minutes_min && minutes <= bounds.minutes_max) ||
            minutes === savedMinutes) &&
        checks !== null &&
        Number.isInteger(checks) &&
        checks >= bounds.recovery_min &&
        checks <= bounds.recovery_max &&
        value.alert_confirms_other_checks !== null &&
        value.alert_reminders !== null
    );
}

export function sensitivityLabel(
    value: Sensitivity,
    t: (key: string, params?: Record<string, unknown>) => string,
): string {
    return value.preset
        ? t(`alert_sensitivity.preset.${value.preset}.label`)
        : t('alert_sensitivity.custom_summary', {
              minutes: t('alert_sensitivity.failed_minutes', {
                  count: value.alert_after_minutes,
              }),
              checks: value.alert_recovery_checks,
              count: value.alert_recovery_checks,
          });
}

// The example strip: five-minute squares, at least 24 (two hours), so every example fits.
const squareMinutes = 5;
const squares = 24;
const exampleOpeningChecks = 2;
// The standard schedule's first reminder; daily reminders start after 24 h and stay out of the examples.
const firstStandardReminder = 60;

type ExampleMessage = 'alert' | 'reminder' | 'recovery';
type ExampleRow = { minute: number; text: string; message?: ExampleMessage };
type OutageExample = { duration: number; rows: ExampleRow[] };

const clock = (minute: number) =>
    `${Math.floor(minute / 60)}:${String(minute % 60).padStart(2, '0')}`;

/**
 * Outage lengths that show what this timing does: one that stays shorter than the alert delay (no message), one that
 * crosses it, and, with the standard reminder schedule, one long enough for the first reminder.
 */
export function exampleDurations(
    minutes: number,
    reminders: Heptapod.Enums.AlertReminders,
): number[] {
    const alertMinute = Math.max(exampleOpeningChecks, minutes);
    return [
        Math.max(1, Math.floor(alertMinute * 0.8)),
        alertMinute + 15,
        ...(reminders === 'standard'
            ? [alertMinute + firstStandardReminder + 30]
            : []),
    ];
}

export function exampleMessages(
    duration: number,
    minutes: number,
    checks: number,
    reminders: Heptapod.Enums.AlertReminders,
): { kind: ExampleMessage; minute: number }[] {
    const alertMinute = Math.max(exampleOpeningChecks, minutes);
    if (alertMinute > duration) return [];
    const reminder = alertMinute + firstStandardReminder;
    return [
        { kind: 'alert', minute: alertMinute },
        ...(reminders === 'standard' && reminder < duration
            ? [{ kind: 'reminder' as const, minute: reminder }]
            : []),
        { kind: 'recovery', minute: duration + checks },
    ];
}

const messageLabel: Record<ExampleMessage, string> = {
    alert: 'alert_sensitivity.example.down_alert',
    reminder: 'alert_sensitivity.example.reminder',
    recovery: 'alert_sensitivity.example.recovered',
};

function MessageNumber({ n }: { n: number }) {
    return (
        <span className="flex size-4 shrink-0 items-center justify-center rounded-full bg-foreground font-mono text-micro leading-none text-background">
            {n}
        </span>
    );
}

/** "Down for 24 min → no message": the duration and the count in the foreground, the arrow muted. */
function ExampleHeadline({ title, count }: { title: string; count: number }) {
    const { t } = useTranslation();
    return (
        <p className="flex flex-wrap items-center gap-x-1.5 text-sm">
            <span className="font-medium">{title}</span>
            <ArrowRight
                className="size-3.5 text-muted-foreground"
                aria-hidden="true"
            />
            <span className="sr-only">: </span>
            <span
                className={cn(
                    count === 0 ? 'text-muted-foreground' : 'font-medium',
                )}
            >
                {count === 0
                    ? t('alert_sensitivity.example.no_message')
                    : t('alert_sensitivity.example.messages', {
                          count,
                          formattedCount: count,
                      })}
            </span>
        </p>
    );
}

/** Squares for the outage, a numbered marker above the square of each message. Decorative: the list says it all. */
function NumberedStrip({ example }: { example: OutageExample }) {
    const messages = example.rows.filter((row) => row.message);
    const lastMinute = Math.max(
        example.duration,
        ...messages.map((row) => row.minute),
    );
    const count = Math.max(
        squares,
        Math.ceil((lastMinute + 1) / squareMinutes),
    );
    const columns = { gridTemplateColumns: `repeat(${count}, minmax(0, 1fr))` };
    const squareOf = (minute: number) =>
        Math.min(count, Math.max(1, Math.ceil(minute / squareMinutes)));
    const marked = new Set(messages.map((row) => squareOf(row.minute)));
    return (
        <div className="max-w-[460px] space-y-1" aria-hidden="true">
            <div className="grid h-4 gap-[3px]" style={columns}>
                {messages.map((row, index) => (
                    <span
                        key={index}
                        data-message-marker
                        className="row-start-1 flex justify-center"
                        style={{ gridColumn: squareOf(row.minute) }}
                    >
                        <MessageNumber n={index + 1} />
                    </span>
                ))}
            </div>
            <div className="grid gap-[3px]" style={columns}>
                {Array.from({ length: count }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'aspect-square max-h-[11px] rounded-[2px]',
                            index * squareMinutes < example.duration
                                ? 'bg-(--status-down)'
                                : 'bg-muted-foreground/15',
                            marked.has(index + 1) &&
                                'outline-2 outline-offset-1 outline-foreground',
                        )}
                    />
                ))}
            </div>
        </div>
    );
}

/** Messages are bold rows led by their strip number; everything else is a small grey row. */
function ExampleRows({ rows }: { rows: ExampleRow[] }) {
    const { t } = useTranslation();
    let number = 0;
    return (
        <ol className="space-y-1">
            {rows.map((row, index) => {
                if (!row.message)
                    return (
                        <li
                            key={index}
                            className="flex items-baseline gap-2 text-xs text-muted-foreground"
                        >
                            <span className="w-9 shrink-0 font-mono tabular-nums">
                                {clock(row.minute)}
                            </span>
                            <span
                                className="w-4 shrink-0 text-center"
                                aria-hidden="true"
                            >
                                ·
                            </span>
                            <span className="min-w-0">{row.text}</span>
                        </li>
                    );
                number += 1;
                return (
                    <li
                        key={index}
                        className="flex items-center gap-2 text-sm font-semibold"
                    >
                        <span className="w-9 shrink-0 font-mono tabular-nums">
                            {clock(row.minute)}
                        </span>
                        <MessageNumber n={number} />
                        <span className="flex min-w-0 flex-wrap items-baseline gap-x-1.5">
                            <span>{t(messageLabel[row.message])}</span>
                            <span className="text-xs font-normal text-muted-foreground">
                                {row.text}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

function TimingExample({
    minutes,
    checks,
    confirms,
    reminders,
}: {
    minutes: number;
    checks: number;
    confirms: boolean;
    reminders: Heptapod.Enums.AlertReminders;
}) {
    const { t } = useTranslation();
    const example = useRef<HTMLElement>(null);
    const alertMinute = Math.max(exampleOpeningChecks, minutes);
    const examples: OutageExample[] = exampleDurations(minutes, reminders).map(
        (duration) => {
            const messages = exampleMessages(
                duration,
                minutes,
                checks,
                reminders,
            );
            const rows: ExampleRow[] = [
                { minute: 0, text: t('alert_sensitivity.example.site_down') },
                ...(duration >= exampleOpeningChecks
                    ? [
                          {
                              minute: exampleOpeningChecks,
                              text: t(
                                  'alert_sensitivity.example.incident_opens',
                              ),
                          },
                      ]
                    : []),
                ...messages
                    .filter((message) => message.kind !== 'recovery')
                    .map((message) => ({
                        minute: message.minute,
                        message: message.kind,
                        text:
                            message.kind === 'alert'
                                ? t('alert_sensitivity.example.down_for', {
                                      minutes: message.minute,
                                  })
                                : t('alert_sensitivity.example.still_down'),
                    })),
                {
                    minute: duration,
                    text:
                        messages.length === 0
                            ? t('alert_sensitivity.example.back_before', {
                                  time: clock(alertMinute),
                              })
                            : t('alert_sensitivity.example.site_back'),
                },
                ...messages
                    .filter((message) => message.kind === 'recovery')
                    .map((message) => ({
                        minute: message.minute,
                        message: message.kind,
                        text: t('alert_sensitivity.example.back_for', {
                            count: checks,
                        }),
                    })),
            ];
            return { duration, rows };
        },
    );
    const timingKey = `${minutes}-${checks}-${reminders}`;
    const previousKey = useRef(timingKey);
    useLayoutEffect(() => {
        const changed = previousKey.current !== timingKey;
        previousKey.current = timingKey;
        if (
            !changed ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        // The markers fade in where the new timing puts the messages; the squares stay put.
        const animations = Array.from(
            example.current?.querySelectorAll('[data-message-marker]') ?? [],
        ).map((marker) =>
            marker.animate([{ opacity: 0 }, { opacity: 1 }], {
                duration: 260,
                easing: EASE_OUT,
                fill: 'backwards',
            }),
        );
        return () => animations.forEach((animation) => animation.cancel());
    }, [timingKey]);
    return (
        <section
            ref={example}
            data-sensitivity-example
            className="space-y-4 rounded-lg border bg-muted/20 p-4"
            aria-label={t('alert_sensitivity.preview_title')}
        >
            <div className="space-y-1">
                <SectionHeading level={3}>
                    {t('alert_sensitivity.preview_title')}
                </SectionHeading>
                <p className="text-xs text-muted-foreground">
                    {t('alert_sensitivity.preview_assumption', {
                        checks: exampleOpeningChecks,
                    })}
                </p>
            </div>
            {examples.map((item) => (
                <div
                    key={item.duration}
                    data-sensitivity-outage={item.duration}
                    className="space-y-2"
                >
                    <ExampleHeadline
                        title={t('alert_sensitivity.example.down_for', {
                            minutes: item.duration,
                        })}
                        count={item.rows.filter((row) => row.message).length}
                    />
                    <NumberedStrip example={item} />
                    <ExampleRows rows={item.rows} />
                </div>
            ))}
            {confirms && (
                <div className="space-y-2 border-t pt-3">
                    <ExampleHeadline
                        title={t('alert_sensitivity.example.check_fails_once')}
                        count={0}
                    />
                    <ExampleRows
                        rows={[
                            {
                                minute: 0,
                                text: t(
                                    'alert_sensitivity.example.check_waits',
                                ),
                            },
                            {
                                minute: 10,
                                text: t(
                                    'alert_sensitivity.example.confirming_passes',
                                ),
                            },
                        ]}
                    />
                </div>
            )}
        </section>
    );
}

export function AlertSensitivityOptions({
    value,
    presets,
    team,
    onChange,
    errors,
    disabled = false,
    savedMinutes,
    bounds,
}: {
    value: SensitivityDraft;
    bounds: SensitivityBounds;
    savedMinutes: number;
    presets: Sensitivity[];
    team?: Sensitivity;
    onChange: (value: SensitivityDraft) => void;
    errors: Partial<Record<keyof SensitivityDraft, string>>;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const id = useId();
    const choice = sensitivityChoice(value, presets);
    const inheriting = choice === 'inherit';
    // While inheriting, the fields show the team default read-only; picking a preset starts the override.
    const effective = inheriting && team ? draftOf(team) : value;
    const fieldsDisabled = disabled || inheriting;
    // Unparsable typed text stays visible in the field; the draft holds null so validSensitivity blocks the save.
    const [unparsable, setUnparsable] = useState<
        Partial<Record<'alert_after_minutes' | 'alert_recovery_checks', string>>
    >({});
    const numberField = (
        field: 'alert_after_minutes' | 'alert_recovery_checks',
    ) => ({
        value: unparsable[field] ?? effective[field],
        invalid: !!errors[field] || unparsable[field] !== undefined,
        onValueChange: (next: NumberFieldValue) => {
            setUnparsable((current) => ({
                ...current,
                [field]: typeof next === 'string' ? next : undefined,
            }));
            onChange({
                ...value,
                [field]: typeof next === 'number' ? next : null,
            });
        },
    });
    const errorFor = (field: keyof SensitivityDraft) =>
        (field === 'alert_after_minutes' ||
            field === 'alert_recovery_checks') &&
        unparsable[field] !== undefined
            ? 'Enter a number.'
            : errors[field];
    const keepsExistingMinutes =
        value.alert_after_minutes === savedMinutes &&
        (savedMinutes < bounds.minutes_min ||
            savedMinutes > bounds.minutes_max);
    const select = (next: SensitivityChoice) => {
        if (next === 'custom') return;
        setUnparsable({});
        if (next === 'inherit') {
            onChange(inheritedDraft);
            return;
        }
        const preset = presets.find((item) => item.preset === next);
        if (!preset)
            throw new Error(`Unknown alert sensitivity preset: ${next}`);
        onChange(draftOf(preset));
    };
    const options: {
        value: SensitivityChoice;
        label: string;
        description: string;
        badge?: React.ReactNode;
        disabled?: boolean;
    }[] = presets.map((preset) => {
        if (preset.preset === null)
            throw new Error('A preset must have a named sensitivity.');
        return {
            value: preset.preset,
            label: sensitivityLabel(preset, t),
            description: t(
                `alert_sensitivity.preset.${preset.preset}.consequence`,
            ),
            badge:
                !team && preset.preset === 'relaxed' ? (
                    <Badge variant="secondary">
                        {t('alert_sensitivity.default')}
                    </Badge>
                ) : undefined,
        };
    });
    if (team)
        options.unshift({
            value: 'inherit',
            label: t('alert_sensitivity.team_default', {
                preset: sensitivityLabel(team, t),
            }),
            description: t('alert_sensitivity.inherit_description'),
        });
    // Custom is never picked: it is what the control shows once the fields below match no preset.
    options.push({
        value: 'custom',
        label: t('alert_sensitivity.custom'),
        description: t('alert_sensitivity.custom_consequence'),
        disabled: choice !== 'custom',
    });
    return (
        <div className="space-y-4">
            <RadioCards
                label={t('alert_sensitivity.title')}
                value={choice}
                options={options}
                onChange={select}
                disabled={disabled}
            />
            <div
                data-sensitivity-fields
                className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2"
            >
                {keepsExistingMinutes && (
                    <p className="text-xs leading-relaxed text-muted-foreground sm:col-span-2">
                        {t('alert_sensitivity.existing_custom', {
                            min: bounds.minutes_min,
                            max: bounds.minutes_max,
                        })}{' '}
                        <InfoTooltip>
                            {t('alert_sensitivity.existing_custom.detail', {
                                min: bounds.minutes_min,
                                max: bounds.minutes_max,
                            })}
                        </InfoTooltip>
                    </p>
                )}
                <div className="space-y-2">
                    <Label htmlFor={`${id}-minutes`}>
                        {t('alert_sensitivity.minutes_label')}
                    </Label>
                    <NumberField
                        id={`${id}-minutes`}
                        describedBy={`${id}-minutes-bounds ${id}-minutes-error`}
                        {...numberField('alert_after_minutes')}
                        min={
                            keepsExistingMinutes
                                ? bounds.stored_minutes_min
                                : bounds.minutes_min
                        }
                        max={
                            keepsExistingMinutes
                                ? bounds.stored_minutes_max
                                : bounds.minutes_max
                        }
                        required
                        disabled={fieldsDisabled}
                    />
                    <p
                        id={`${id}-minutes-bounds`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('alert_sensitivity.minutes_bounds', {
                            min: bounds.minutes_min,
                            max: bounds.minutes_max,
                        })}
                    </p>
                    <InputError
                        id={`${id}-minutes-error`}
                        message={errorFor('alert_after_minutes')}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${id}-checks`}>
                        {t('alert_sensitivity.checks_label')}
                    </Label>
                    <NumberField
                        id={`${id}-checks`}
                        describedBy={`${id}-checks-bounds ${id}-checks-error`}
                        {...numberField('alert_recovery_checks')}
                        min={bounds.recovery_min}
                        max={bounds.recovery_max}
                        required
                        disabled={fieldsDisabled}
                    />
                    <p
                        id={`${id}-checks-bounds`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('alert_sensitivity.checks_bounds', {
                            min: bounds.recovery_min,
                            max: bounds.recovery_max,
                        })}
                    </p>
                    <InputError
                        id={`${id}-checks-error`}
                        message={errorFor('alert_recovery_checks')}
                    />
                </div>
                <div className="space-y-2">
                    <Label id={`${id}-confirm-label`}>
                        {t('alert_sensitivity.confirm_label')}
                    </Label>
                    <CheckboxField
                        id={`${id}-confirm`}
                        checked={effective.alert_confirms_other_checks === true}
                        onCheckedChange={(checked) =>
                            onChange({
                                ...value,
                                alert_confirms_other_checks: checked === true,
                            })
                        }
                        description={t('alert_sensitivity.confirm_description')}
                        disabled={fieldsDisabled}
                        {...fieldErrorProps(
                            `${id}-confirm`,
                            errorFor('alert_confirms_other_checks'),
                        )}
                    >
                        {t('alert_sensitivity.confirm_option')}
                    </CheckboxField>
                    <InputError
                        field={`${id}-confirm`}
                        message={errorFor('alert_confirms_other_checks')}
                    />
                </div>
                <div className="space-y-2">
                    <Label id={`${id}-reminders-label`}>
                        {t('alert_sensitivity.reminders_label')}
                    </Label>
                    <RadioGroup
                        id={`${id}-reminders`}
                        aria-labelledby={`${id}-reminders-label`}
                        {...fieldErrorProps(
                            `${id}-reminders`,
                            errorFor('alert_reminders'),
                        )}
                        value={effective.alert_reminders ?? undefined}
                        onValueChange={(next) =>
                            onChange({
                                ...value,
                                alert_reminders:
                                    next as Heptapod.Enums.AlertReminders,
                            })
                        }
                        disabled={fieldsDisabled}
                    >
                        <RadioGroupItem value="standard">
                            {t('alert_sensitivity.reminders.standard')}
                        </RadioGroupItem>
                        <RadioGroupItem value="daily">
                            {t('alert_sensitivity.reminders.daily')}
                        </RadioGroupItem>
                    </RadioGroup>
                    <InputError
                        field={`${id}-reminders`}
                        message={errorFor('alert_reminders')}
                    />
                </div>
            </div>
            {validSensitivity(
                effective,
                bounds,
                inheriting ? team?.alert_after_minutes : savedMinutes,
            ) &&
                effective.alert_after_minutes !== null &&
                effective.alert_recovery_checks !== null && (
                    <>
                        <p className="text-xs text-muted-foreground">
                            {t('alert_sensitivity.recovery_confirmed', {
                                count: effective.alert_recovery_checks,
                            })}
                        </p>
                        <TimingExample
                            minutes={effective.alert_after_minutes}
                            checks={effective.alert_recovery_checks}
                            confirms={
                                effective.alert_confirms_other_checks === true
                            }
                            reminders={effective.alert_reminders ?? 'standard'}
                        />
                    </>
                )}
            <p className="text-xs leading-relaxed text-muted-foreground">
                {t('alert_sensitivity.footer')}
            </p>
        </div>
    );
}
