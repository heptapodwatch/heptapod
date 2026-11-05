import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/** Monday-first display order with ISO weekday numbers (1 = Monday … 7 = Sunday). */
const weekdays = [
    { iso: 1, short: 'Mon', long: 'Monday' },
    { iso: 2, short: 'Tue', long: 'Tuesday' },
    { iso: 3, short: 'Wed', long: 'Wednesday' },
    { iso: 4, short: 'Thu', long: 'Thursday' },
    { iso: 5, short: 'Fri', long: 'Friday' },
    { iso: 6, short: 'Sat', long: 'Saturday' },
    { iso: 7, short: 'Sun', long: 'Sunday' },
] as const;

/**
 * Stored weekday numbering differs per domain: ISO (1–7, Sunday = 7) for routing and digests, JavaScript (0–6, Sunday = 0)
 * for maintenance. The control always displays Monday first and returns values in the caller's numbering, sorted ascending
 * (the payload order the forms submitted before).
 */
export type WeekdayNumbering = 'iso' | 'sunday-zero';

const toStored = (iso: number, numbering: WeekdayNumbering) =>
    numbering === 'sunday-zero' && iso === 7 ? 0 : iso;

/** Multi-select weekday chips (Mon–Sun), keyboard reachable with arrow keys, one pattern app-wide. */
export function WeekdayToggleGroup({
    id,
    value,
    onValueChange,
    numbering,
    labelledBy,
    describedBy,
    invalid,
    disabled,
    className,
}: {
    id?: string;
    value: number[];
    onValueChange: (value: number[]) => void;
    numbering: WeekdayNumbering;
    labelledBy: string;
    describedBy?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    return (
        <ToggleGroup
            id={id}
            type="multiple"
            aria-labelledby={labelledBy}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            disabled={disabled}
            spacing={1}
            value={value.map(String)}
            onValueChange={(next) =>
                onValueChange(next.map(Number).sort((a, b) => a - b))
            }
            className={cn('flex-wrap gap-1', className)}
        >
            {weekdays.map((day) => {
                const stored = toStored(day.iso, numbering);
                return (
                    <ToggleGroupItem
                        key={day.iso}
                        id={id ? `${id}-${stored}` : undefined}
                        value={String(stored)}
                        aria-label={t(day.long)}
                        className="h-10 min-w-11 border border-input bg-muted px-2.5 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground sm:h-8"
                    >
                        {t(day.short)}
                    </ToggleGroupItem>
                );
            })}
        </ToggleGroup>
    );
}
