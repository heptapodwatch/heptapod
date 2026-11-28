import InputError, { fieldErrorProps } from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { NumberField } from '@/components/ui/number-field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/hooks/use-format';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

const units = { minute: 60, hour: 3600, day: 86400 };
type IntervalUnit = keyof typeof units;

export function isIntervalUnit(unit: unknown): unit is IntervalUnit {
    return typeof unit === 'string' && Object.hasOwn(units, unit);
}

export function intervalFields(seconds: number) {
    const unit =
        seconds % units.day === 0
            ? 'day'
            : seconds % units.hour === 0
              ? 'hour'
              : 'minute';
    return { unit, amount: seconds / units[unit] } as const;
}

export function intervalSeconds(
    amount: string | number,
    unit: IntervalUnit,
): number | null {
    const value = Number(amount);
    return Number.isInteger(value) && value >= 1 && value <= 60
        ? value * units[unit]
        : null;
}

export function CheckIntervalField({
    presets,
    amount,
    unit,
    errors,
    onChange,
}: {
    presets: readonly number[];
    amount: string | number;
    unit: IntervalUnit;
    errors: { frequency_value?: string; frequency_unit?: string };
    onChange: (amount: string | number, unit: IntervalUnit) => void;
}) {
    const { t } = useTranslation();
    const { intervalPhrase } = useFormat();
    const id = useId();
    const [editingCustom, setEditingCustom] = useState(false);
    const seconds = intervalSeconds(amount, unit);
    const match = presets.find((minutes) => minutes * 60 === seconds);
    const choice =
        editingCustom || match === undefined ? 'custom' : String(match);
    const presetError =
        choice === 'custom'
            ? undefined
            : (errors.frequency_value ?? errors.frequency_unit);
    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor={id}>{t('Check interval')}</Label>
                <Select
                    value={choice}
                    onValueChange={(value) => {
                        const custom = value === 'custom';
                        setEditingCustom(custom);
                        if (!custom) {
                            const preset = intervalFields(Number(value) * 60);
                            onChange(preset.amount, preset.unit);
                        }
                    }}
                >
                    {/* form-select: interval-presets Fixed interval suggestions plus Custom; not entity data. */}
                    <SelectTrigger
                        id={id}
                        className="w-full"
                        {...fieldErrorProps(id, presetError)}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent position="popper">
                        {presets.map((minutes) => (
                            <SelectItem key={minutes} value={String(minutes)}>
                                {intervalPhrase(minutes * 60)}
                            </SelectItem>
                        ))}
                        <SelectItem value="custom">{t('Custom…')}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError field={id} message={presetError} />
            </div>
            {choice === 'custom' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor={`${id}-amount`}>
                            {t('Interval amount')}
                        </Label>
                        <NumberField
                            id={`${id}-amount`}
                            min={1}
                            max={60}
                            required
                            invalid={!!errors.frequency_value}
                            describedBy={
                                fieldErrorProps(
                                    `${id}-amount`,
                                    errors.frequency_value,
                                )['aria-describedby']
                            }
                            value={amount === '' ? null : amount}
                            onValueChange={(value) =>
                                onChange(value ?? '', unit)
                            }
                        />
                        <InputError
                            field={`${id}-amount`}
                            message={errors.frequency_value}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label id={`${id}-unit-label`}>
                            {t('Interval unit')}
                        </Label>
                        <RadioGroup
                            id={`${id}-unit`}
                            aria-labelledby={`${id}-unit-label`}
                            {...fieldErrorProps(
                                `${id}-unit`,
                                errors.frequency_unit,
                            )}
                            value={unit}
                            onValueChange={(value) => {
                                if (!isIntervalUnit(value))
                                    throw new Error('Invalid interval unit');
                                onChange(amount, value);
                            }}
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
                            field={`${id}-unit`}
                            message={errors.frequency_unit}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}
