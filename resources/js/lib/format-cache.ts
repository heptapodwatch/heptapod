// TypeScript's Intl declarations do not yet include the native DurationFormat API.
type DurationFormatOptions = {
    style: 'narrow' | 'short';
    hours?: 'short';
    hoursDisplay?: 'auto' | 'always';
    minutesDisplay?: 'auto' | 'always';
    secondsDisplay?: 'auto' | 'always';
};
type DurationFormat = { format(duration: Record<string, number>): string };
const { DurationFormat } = Intl as typeof Intl & {
    DurationFormat: new (
        locale: string,
        options: DurationFormatOptions,
    ) => DurationFormat;
};

// Building an Intl formatter is far slower than using one, and table rows format hundreds of values per render.
const formatters = new Map<string, unknown>();

function cached<T>(
    kind: string,
    locale: string,
    options: object | undefined,
    create: () => T,
): T {
    // The key holds the locale, the time zone and every other option, so no two configurations share a formatter.
    const key = JSON.stringify([kind, locale, options ?? null]);
    let formatter = formatters.get(key) as T | undefined;
    if (formatter === undefined) {
        formatter = create();
        formatters.set(key, formatter);
    }
    return formatter;
}

export const dateTimeFormat = (
    locale: string,
    options?: Intl.DateTimeFormatOptions,
) =>
    cached(
        'DateTimeFormat',
        locale,
        options,
        () => new Intl.DateTimeFormat(locale, options),
    );

export const numberFormat = (
    locale: string,
    options?: Intl.NumberFormatOptions,
) =>
    cached(
        'NumberFormat',
        locale,
        options,
        () => new Intl.NumberFormat(locale, options),
    );

export const relativeTimeFormat = (
    locale: string,
    options?: Intl.RelativeTimeFormatOptions,
) =>
    cached(
        'RelativeTimeFormat',
        locale,
        options,
        () => new Intl.RelativeTimeFormat(locale, options),
    );

export const durationFormat = (
    locale: string,
    options: DurationFormatOptions,
) =>
    cached(
        'DurationFormat',
        locale,
        options,
        () => new DurationFormat(locale, options),
    );
