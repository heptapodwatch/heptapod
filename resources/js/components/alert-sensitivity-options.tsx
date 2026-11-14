import { EASE_OUT } from '@/lib/motion';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
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

// The example strip: 24 squares of 5 minutes each, so the 60-minute preset fits.
const squareMinutes = 5;
const squares = 24;
const squareOf = (minute: number) => Math.ceil(minute / squareMinutes);

function TimingPin({
    square,
    visible,
    recovery = false,
}: {
    square: number;
    visible: boolean;
    recovery?: boolean;
}) {
    const { t } = useTranslation();
    const minuteFraction = (square - 0.5) / squares;
    const pin = useRef<HTMLSpanElement>(null);
    const label = useRef<HTMLSpanElement>(null);
    const [labelOffset, setLabelOffset] = useState(0);
    useLayoutEffect(() => {
        const plot = pin.current?.parentElement;
        const text = label.current;
        if (!plot || !text) return;
        const measure = () => {
            const width = plot.clientWidth;
            const x = minuteFraction * (width + 3) - 1.5;
            const labelWidth = text.offsetWidth;
            setLabelOffset(
                Math.max(
                    1 - x,
                    Math.min(-labelWidth / 2, width - x - labelWidth - 1),
                ),
            );
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(plot);
        observer.observe(text);
        return () => observer.disconnect();
    }, [minuteFraction]);
    const ring = useRef<HTMLSpanElement>(null);
    const previous = useRef({ square, visible });
    useEffect(() => {
        const changed =
            previous.current.square !== square ||
            previous.current.visible !== visible;
        previous.current = { square, visible };
        if (
            recovery ||
            !changed ||
            !visible ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        let pulse: Animation | undefined;
        const timer = window.setTimeout(() => {
            if (
                document.hidden ||
                window.matchMedia('(prefers-reduced-motion: reduce)').matches
            )
                return;
            pulse = ring.current?.animate(
                [
                    { transform: 'scale(1)', opacity: 0.65 },
                    { transform: 'scale(2.3)', opacity: 0 },
                ],
                { duration: 400, easing: EASE_OUT },
            );
        }, 520);
        return () => {
            window.clearTimeout(timer);
            pulse?.cancel();
        };
    }, [square, visible, recovery]);
    return (
        <span
            ref={pin}
            data-sensitivity-pin={recovery ? 'recovery' : 'alert'}
            aria-hidden="true"
            className={cn(
                'alert-sensitivity-pin pointer-events-none absolute inset-x-0',
                recovery ? 'top-[45px]' : 'top-1',
            )}
            style={{
                // Account for the grid's 3px gaps to center the stem on its square.
                transform: `translateX(calc(${minuteFraction * 100}% + ${minuteFraction * 3 - 1.5}px))`,
                opacity: visible ? 1 : 0,
            }}
        >
            <span
                ref={label}
                className={cn(
                    'alert-sensitivity-pin-label absolute w-max rounded px-1.5 py-1 font-mono text-micro leading-none',
                    recovery
                        ? 'top-[11px] border bg-card text-muted-foreground'
                        : 'bg-foreground text-background',
                )}
                style={{
                    transform: `translateX(${labelOffset}px)`,
                }}
            >
                {t(
                    recovery
                        ? 'alert_sensitivity.pin_recovered'
                        : 'alert_sensitivity.pin_alert',
                )}
            </span>
            <span
                className={cn(
                    'absolute w-px bg-foreground',
                    recovery
                        ? 'top-0 h-[11px] bg-border'
                        : 'top-[18px] h-[12px]',
                )}
            />
            {!recovery && (
                <span
                    ref={ring}
                    data-alert-pin-pulse
                    className="absolute top-[30px] left-0 size-3 -translate-x-1/2 rounded-full border border-foreground opacity-0"
                />
            )}
        </span>
    );
}

const exampleOpeningChecks = 2;

function TimingExample({
    minutes,
    checks,
}: {
    minutes: number;
    checks: number;
}) {
    const { t } = useTranslation();
    const example = useRef<HTMLElement>(null);
    const alertMinute = Math.max(exampleOpeningChecks, minutes);
    const previousMinute = useRef(alertMinute);
    useLayoutEffect(() => {
        const changed = previousMinute.current !== alertMinute;
        previousMinute.current = alertMinute;
        if (
            !changed ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        const animations = Array.from(
            example.current?.querySelectorAll('[data-alert-minute-cell]') ?? [],
        ).map((cell) =>
            cell.animate([{ opacity: 0 }, { opacity: 1 }], {
                delay: 520,
                duration: 260,
                easing: EASE_OUT,
                fill: 'backwards',
            }),
        );
        return () => animations.forEach((animation) => animation.cancel());
    }, [alertMinute]);
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
            {[26, 90].map((duration) => {
                // This example uses the default two-failure incident opening, including for a saved API value of 1.
                const alerted = alertMinute <= duration;
                const recovery = duration + checks;
                return (
                    <div key={duration} className="space-y-1">
                        <p className="text-xs text-muted-foreground">
                            {t(
                                duration === 26
                                    ? 'alert_sensitivity.interruption'
                                    : 'alert_sensitivity.outage',
                            )}
                        </p>
                        <div
                            className="relative h-[80px] max-w-[360px] overflow-x-clip"
                            aria-hidden="true"
                        >
                            <div className="absolute inset-x-0 top-[34px] grid grid-cols-24 gap-[3px]">
                                {Array.from({ length: squares }, (_, index) => (
                                    <span
                                        key={index}
                                        className={cn(
                                            'relative aspect-square max-h-[11px] rounded-[2px]',
                                            index * squareMinutes < duration
                                                ? 'bg-(--status-down)'
                                                : 'bg-muted-foreground/15',
                                        )}
                                    >
                                        {alerted &&
                                            index + 1 ===
                                                squareOf(alertMinute) && (
                                                <span
                                                    data-alert-minute-cell
                                                    className="absolute -inset-[3px] rounded-full border border-foreground"
                                                />
                                            )}
                                    </span>
                                ))}
                            </div>
                            <TimingPin
                                square={Math.min(
                                    squareOf(alertMinute),
                                    squares,
                                )}
                                visible={alerted}
                            />
                            <TimingPin
                                square={Math.min(squareOf(recovery), squares)}
                                visible={alerted}
                                recovery
                            />
                        </div>
                        <p
                            className="min-h-10 text-xs leading-relaxed text-muted-foreground"
                            aria-live="polite"
                        >
                            {alerted
                                ? t('alert_sensitivity.preview_alert', {
                                      minutes: alertMinute,
                                      recovery,
                                  })
                                : t('alert_sensitivity.preview_quiet')}
                        </p>
                    </div>
                );
            })}
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
                        />
                    </>
                )}
            <p className="text-xs leading-relaxed text-muted-foreground">
                {t('alert_sensitivity.footer')}
            </p>
        </div>
    );
}
