import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Popover } from 'radix-ui';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const pad = (value: number) => String(value).padStart(2, '0');
export const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;

function typedTime(raw: string): string {
    const digits = raw.replace(/\D/g, '').slice(0, 4);
    return digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
}

type TimeFieldProps = {
    id?: string;
    name?: string;
    value: string;
    onValueChange: (value: string) => void;
    disabled?: boolean;
    required?: boolean;
    invalid?: boolean;
    describedBy?: string;
    className?: string;
    'aria-label'?: string;
    /** Reports unparsable typed text so the caller can show InputError and block submit. */
    onInvalidChange?: (invalid: boolean) => void;
};

/** Branded 24-hour HH:MM entry. Emits only a complete valid time or ''. */
export function TimeField({
    id,
    name,
    value,
    onValueChange,
    disabled,
    required,
    invalid,
    describedBy,
    className,
    'aria-label': ariaLabel,
    onInvalidChange,
}: TimeFieldProps) {
    const [text, setText] = useState(value);
    const [invalidText, setInvalidText] = useState(false);
    useEffect(() => setText(value), [value]);
    useEffect(() => {
        onInvalidChange?.(invalidText);
    }, [invalidText, onInvalidChange]);
    return (
        <Input
            id={id}
            name={name}
            type="text"
            inputMode="numeric"
            autoComplete="off"
            placeholder="HH:MM"
            className={cn('font-mono tabular-nums', className)}
            value={text}
            disabled={disabled}
            required={required}
            aria-describedby={describedBy}
            aria-invalid={invalid || invalidText || undefined}
            aria-label={ariaLabel}
            onChange={(event) => {
                const next = typedTime(event.target.value);
                setText(next);
                if (next === '' || timePattern.test(next)) {
                    setInvalidText(false);
                    onValueChange(next);
                } else if (next.length === 5) setInvalidText(true);
            }}
            onBlur={() => {
                // Fail loud: keep the typed text, never revert silently.
                if (text !== '' && !timePattern.test(text)) setInvalidText(true);
            }}
        />
    );
}

type DateParts = { date: string; time: string };

const monthNames: Record<string, number> = {
    jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3, apr: 4, april: 4,
    may: 5, jun: 6, june: 6, jul: 7, july: 7, aug: 8, august: 8, sep: 9,
    september: 9, oct: 10, october: 10, nov: 11, november: 11, dec: 12, december: 12,
};

function partsFrom(
    year: number, month: number, day: number, hour: number, minute: number,
): DateParts | null {
    const check = new Date(Date.UTC(year, month - 1, day));
    if (
        check.getUTCFullYear() !== year || check.getUTCMonth() !== month - 1 ||
        check.getUTCDate() !== day || hour > 23 || minute > 59
    )
        return null;
    return {
        date: `${year}-${pad(month)}-${pad(day)}`,
        time: `${pad(hour)}:${pad(minute)}`,
    };
}

/** ISO, the DE dotted display form and the EN month-name display form. */
function parseDateTime(value: string): DateParts | null {
    const iso = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/.exec(value);
    if (iso) {
        const [year, month, day, hour, minute] = iso.slice(1).map(Number) as [
            number, number, number, number, number,
        ];
        return partsFrom(year, month, day, hour, minute);
    }
    const dotted = /^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}):(\d{2})$/.exec(value);
    if (dotted) {
        const [day, month, year, hour, minute] = dotted.slice(1).map(Number) as [
            number, number, number, number, number,
        ];
        return partsFrom(year, month, day, hour, minute);
    }
    const named = /^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4}),?\s+(\d{1,2}):(\d{2})$/.exec(value);
    if (named) {
        const month = monthNames[(named[1] ?? '').toLowerCase()];
        if (!month) return null;
        return partsFrom(
            Number(named[3]), month, Number(named[2]), Number(named[4]), Number(named[5]),
        );
    }
    return null;
}



const fieldTextClass = 'font-mono text-base tabular-nums md:text-cell';
const zoneChipClass =
    'pointer-events-none shrink-0 whitespace-nowrap rounded-full border bg-background px-1.5 py-0.5 text-label text-muted-foreground';
/** Input padding left of the value (px-3) and the calendar button area right of the chip (right-10). */
const fieldPaddingStart = 12;
const calendarArea = 40;
const chipGap = 4;

/** The readable short form of an IANA zone: its city ("Europe/Berlin" → "Berlin", "America/Argentina/Buenos_Aires" → "Buenos Aires"). */
export function zoneCity(timezone: string): string {
    return (timezone.split('/').at(-1) ?? timezone).replaceAll('_', ' ');
}

/**
 * The timezone chip never truncates: it shows the full zone while it fits beside the typed value (or the placeholder),
 * otherwise the city, and the input reserves exactly the chip's width so the value never runs under it.
 */
function useZoneChip(timezone: string | undefined, valueText: string) {
    const fieldRef = useRef<HTMLDivElement>(null);
    const chipRef = useRef<HTMLSpanElement>(null);
    const fullRef = useRef<HTMLSpanElement>(null);
    const valueRef = useRef<HTMLSpanElement>(null);
    const [layout, setLayout] = useState<{ compact: boolean; padding?: number }>({ compact: false });
    useLayoutEffect(() => {
        const field = fieldRef.current;
        if (!timezone || !field) return;
        const measure = () => {
            // Not laid out yet (hidden sheet, test DOM): keep the full zone.
            if (field.clientWidth === 0) return;
            const full = fullRef.current?.offsetWidth ?? 0;
            const value = valueRef.current?.offsetWidth ?? 0;
            const compact =
                fieldPaddingStart + value + chipGap + full + calendarArea > field.clientWidth;
            setLayout((current) => (current.compact === compact ? current : { ...current, compact }));
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(field);
        return () => observer.disconnect();
    }, [timezone, valueText]);
    useLayoutEffect(() => {
        const chip = chipRef.current?.offsetWidth;
        if (!timezone || !chip) return;
        const padding = chip + calendarArea + chipGap;
        setLayout((current) => (current.padding === padding ? current : { ...current, padding }));
    }, [timezone, layout.compact]);
    return { fieldRef, chipRef, fullRef, valueRef, valueText, ...layout };
}

type DateTimeFieldProps = {
    id?: string;
    name?: string;
    /** `YYYY-MM-DDTHH:MM` (the former datetime-local value) or ''. */
    value: string;
    onValueChange: (value: string) => void;
    disabled?: boolean;
    required?: boolean;
    invalid?: boolean;
    describedBy?: string;
    /** Shown as a suffix chip, e.g. `UTC` or the form's IANA timezone. */
    timezone?: string;
    /** Reports unparsable typed text so the caller can show InputError and block submit. */
    onInvalidChange?: (invalid: boolean) => void;
    className?: string;
};

/**
 * One branded date+time entry app-wide: direct 24-hour keyboard entry in
 * `YYYY-MM-DD HH:MM`, plus a calendar popover with a TimeField. The wall
 * time is in the caller's timezone semantics; the emitted value is exactly
 * the former native datetime-local string.
 */
export function DateTimeField({
    id,
    name,
    value,
    onValueChange,
    disabled,
    required,
    invalid,
    describedBy,
    timezone,
    className,
    onInvalidChange,
}: DateTimeFieldProps) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const [text, setText] = useState(value.replace('T', ' '));
    const [open, setOpen] = useState(false);
    const parsed = parseDateTime(value);
    const zone = useZoneChip(timezone, text || (locale.startsWith('de') ? 'TT.MM.JJJJ HH:MM' : 'YYYY-MM-DD HH:MM'));
    const todayDate = new Date();
    const today = `${todayDate.getFullYear()}-${pad(todayDate.getMonth() + 1)}-${pad(todayDate.getDate())}`;
    const [view, setView] = useState(() => {
        const base = parsed ? new Date(`${parsed.date}T00:00:00Z`) : todayDate;
        return { year: base.getUTCFullYear(), month: base.getUTCMonth() };
    });
    const [invalidText, setInvalidText] = useState(false);
    useEffect(() => {
        onInvalidChange?.(invalidText);
    }, [invalidText, onInvalidChange]);
    const [focusedDate, setFocusedDate] = useState(parsed?.date ?? today);
    const [draftTime, setDraftTime] = useState(parsed?.time ?? '');
    const gridRef = useRef<HTMLDivElement>(null);
    useEffect(() => setText(value.replace('T', ' ')), [value]);
    useEffect(() => {
        if (!open) return;
        const base = parseDateTime(value)
            ? new Date(`${parseDateTime(value)!.date}T00:00:00Z`)
            : new Date();
        setView({ year: base.getUTCFullYear(), month: base.getUTCMonth() });
        setFocusedDate(parseDateTime(value)?.date ?? today);
        // View and focus reset only when the popover opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open ]);
    useEffect(() => {
        if (open)
            gridRef.current
                ?.querySelector<HTMLElement>(`[data-date="${focusedDate}"]`)
                ?.focus();
    }, [open, focusedDate, view]);

    const emit = (date: string, time: string) => onValueChange(`${date}T${time}`);
    // Weeks start on Monday everywhere (rule book: dates and durations).
    const firstWeekday = 1;
    const monthStart = new Date(Date.UTC(view.year, view.month, 1));
    const lead = (monthStart.getUTCDay() - firstWeekday + 7) % 7;
    const days = new Date(Date.UTC(view.year, view.month + 1, 0)).getUTCDate();
    const cells: (number | null)[] = [
        ...Array.from({ length: lead }, () => null),
        ...Array.from({ length: days }, (_, index) => index + 1),
    ];
    const weeks: (number | null)[][] = [];
    for (let index = 0; index < cells.length; index += 7)
        weeks.push(cells.slice(index, index + 7));
    const dayLabel = (date: string) =>
        new Intl.DateTimeFormat(locale, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(new Date(`${date}T00:00:00Z`));
    const moveFocus = (date: string) => {
        const next = new Date(`${date}T00:00:00Z`);
        setView({ year: next.getUTCFullYear(), month: next.getUTCMonth() });
        setFocusedDate(date);
    };
    const shiftDate = (date: string, daysDelta: number, monthsDelta = 0) => {
        const next = new Date(`${date}T00:00:00Z`);
        next.setUTCDate(next.getUTCDate() + daysDelta);
        next.setUTCMonth(next.getUTCMonth() + monthsDelta);
        return `${next.getUTCFullYear()}-${pad(next.getUTCMonth() + 1)}-${pad(next.getUTCDate())}`;
    };
    const onGridKeyDown = (event: React.KeyboardEvent) => {
        const current = (event.target as HTMLElement).dataset.date;
        if (!current) return;
        const weekday = (new Date(`${current}T00:00:00Z`).getUTCDay() + 6) % 7; // Monday = 0
        let next: string | null = null;
        if (event.key === 'ArrowRight') next = shiftDate(current, 1);
        else if (event.key === 'ArrowLeft') next = shiftDate(current, -1);
        else if (event.key === 'ArrowDown') next = shiftDate(current, 7);
        else if (event.key === 'ArrowUp') next = shiftDate(current, -7);
        else if (event.key === 'PageDown') next = shiftDate(current, 0, 1);
        else if (event.key === 'PageUp') next = shiftDate(current, 0, -1);
        else if (event.key === 'Home') next = shiftDate(current, -weekday);
        else if (event.key === 'End') next = shiftDate(current, 6 - weekday);
        if (next) {
            event.preventDefault();
            moveFocus(next);
        }
    };
    const weekdayLabels = Array.from({ length: 7 }, (_, index) =>
        new Intl.DateTimeFormat(locale, { weekday: 'narrow', timeZone: 'UTC' }).format(
            new Date(Date.UTC(2024, 0, 7 + ((firstWeekday + index) % 7))),
        ),
    );
    const preview = parsed
        ? new Intl.DateTimeFormat(locale, {
              dateStyle: 'medium',
              timeStyle: 'short',
              hourCycle: 'h23',
              timeZone: 'UTC',
          }).format(new Date(`${parsed.date}T${parsed.time}:00Z`))
        : null;
    const moveMonth = (delta: number) =>
        setView((current) => {
            const next = new Date(Date.UTC(current.year, current.month + delta, 1));
            return { year: next.getUTCFullYear(), month: next.getUTCMonth() };
        });

    return (
        <div ref={zone.fieldRef} className={cn('relative', className)}>
            <Input
                id={id}
                name={name}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                placeholder={locale.startsWith('de') ? 'TT.MM.JJJJ HH:MM' : 'YYYY-MM-DD HH:MM'}
                className={cn(fieldTextClass, 'pr-10')}
                style={zone.padding === undefined ? undefined : { paddingRight: zone.padding }}
                value={text}
                disabled={disabled}
                required={required}
                aria-invalid={invalid || invalidText || undefined}
                aria-describedby={describedBy}
                onChange={(event) => {
                    const typed = event.target.value;
                    setText(typed);
                    const next = parseDateTime(typed.trim());
                    if (next) {
                        setInvalidText(false);
                        emit(next.date, next.time);
                    } else if (typed.trim() === '') {
                        setInvalidText(false);
                        onValueChange('');
                    } else if (/\d{2}:\d{2}$/.test(typed.trim()) && typed.trim().length >= 14)
                        // A finished-looking attempt that does not parse: fail loud at once.
                        setInvalidText(true);
                }}
                onBlur={() => {
                    // Keep the typed text; the caller shows the format error.
                    if (text.trim() !== '' && !parseDateTime(text.trim()))
                        setInvalidText(true);
                }}
            />
            {timezone && (
                <>
                    <span
                        ref={zone.chipRef}
                        data-slot="timezone-chip"
                        className={cn(zoneChipClass, 'absolute top-1/2 right-10 -translate-y-1/2')}
                    >
                        {zone.compact ? zoneCity(timezone) : timezone}
                    </span>
                    {/* Measures the full zone and the field's text so the chip shows the full name whenever it fits. */}
                    <span aria-hidden="true" className="invisible absolute top-0 left-0 flex w-0 overflow-hidden">
                        <span ref={zone.fullRef} className={zoneChipClass}>
                            {timezone}
                        </span>
                        <span ref={zone.valueRef} className={cn(fieldTextClass, 'shrink-0 whitespace-pre')}>
                            {zone.valueText}
                        </span>
                    </span>
                </>
            )}
            <Popover.Root open={open} onOpenChange={setOpen}>
                <Popover.Trigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        disabled={disabled}
                        aria-label={t('Select date')}
                        className="absolute top-1/2 right-1.5 -translate-y-1/2"
                    >
                        <CalendarDays />
                    </Button>
                </Popover.Trigger>
                <Popover.Portal>
                    <Popover.Content
                        align="end"
                        sideOffset={4}
                        collisionPadding={8}
                        onOpenAutoFocus={(event) => {
                            event.preventDefault();
                            gridRef.current
                                ?.querySelector<HTMLElement>(
                                    `[data-date="${focusedDate}"]`,
                                )
                                ?.focus();
                        }}
                        className="z-50 w-72 origin-(--radix-popover-content-transform-origin) rounded-lg border bg-popover p-3 text-popover-foreground shadow-overlay motion-popover"
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">
                                {new Intl.DateTimeFormat(locale, {
                                    month: 'long',
                                    year: 'numeric',
                                    timeZone: 'UTC',
                                }).format(monthStart)}
                            </span>
                            <span className="flex gap-1">
                                <Button type="button" variant="ghost" size="icon-xs" aria-label={t('Previous month')} onClick={() => moveMonth(-1)}>
                                    <ChevronLeft />
                                </Button>
                                <Button type="button" variant="ghost" size="icon-xs" aria-label={t('Next month')} onClick={() => moveMonth(1)}>
                                    <ChevronRight />
                                </Button>
                            </span>
                        </div>
                        <div
                            ref={gridRef}
                            role="grid"
                            aria-label={new Intl.DateTimeFormat(locale, {
                                month: 'long',
                                year: 'numeric',
                                timeZone: 'UTC',
                            }).format(monthStart)}
                            onKeyDown={onGridKeyDown}
                        >
                            <div role="row" className="mt-2 grid grid-cols-7 text-center text-label text-muted-foreground">
                                {weekdayLabels.map((label, index) => (
                                    <span key={index} role="columnheader" className="py-1">{label}</span>
                                ))}
                            </div>
                            {weeks.map((week, weekIndex) => (
                                <div key={weekIndex} role="row" className="grid grid-cols-7">
                                    {week.map((day, index) => {
                                        if (day === null)
                                            return <span key={index} role="gridcell" />;
                                        const date = `${view.year}-${pad(view.month + 1)}-${pad(day)}`;
                                        return (
                                            <button
                                                key={index}
                                                type="button"
                                                role="gridcell"
                                                data-date={date}
                                                tabIndex={date === focusedDate ? 0 : -1}
                                                aria-label={dayLabel(date)}
                                                aria-selected={parsed?.date === date}
                                                aria-current={date === today ? 'date' : undefined}
                                                className={cn(
                                                    'size-10 cursor-pointer justify-self-center rounded-full text-cell hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:size-9',
                                                    parsed?.date === date &&
                                                        'bg-primary text-primary-foreground hover:bg-primary/90',
                                                    date === today &&
                                                        parsed?.date !== date &&
                                                        'ring-1 ring-ring',
                                                )}
                                                onClick={() => {
                                                    setFocusedDate(date);
                                                    emit(
                                                        date,
                                                        parsed?.time ??
                                                            (timePattern.test(draftTime)
                                                                ? draftTime
                                                                : '00:00'),
                                                    );
                                                }}
                                            >
                                                {day}
                                            </button>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                        <div className="mt-3 flex items-center gap-2 border-t pt-3">
                            <TimeField
                                aria-label={t('Time')}
                                value={parsed?.time ?? draftTime}
                                onValueChange={(time) => {
                                    setDraftTime(time);
                                    if (parsed && time) emit(parsed.date, time);
                                }}
                            />
                            {!required && value && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        onValueChange('');
                                        setOpen(false);
                                    }}
                                >
                                    {t('Clear')}
                                </Button>
                            )}
                        </div>
                        {preview && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {preview}
                                {timezone ? ` · ${timezone}` : ''}
                            </p>
                        )}
                    </Popover.Content>
                </Popover.Portal>
            </Popover.Root>
        </div>
    );
}
