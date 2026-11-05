import { useMemo } from 'react';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';

const offsetCache = new Map<string, string>();

/** "UTC+01:00" for a zone right now, via the platform's IANA data (no server round-trip). */
export function timezoneOffset(zone: string): string {
    // Cached per day so a DST switch within a session is picked up.
    const cacheKey = `${zone}@${new Date().toISOString().slice(0, 10)}`;
    const cached = offsetCache.get(cacheKey);
    if (cached) return cached;
    let offset = 'UTC';
    try {
        const part = new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(new Date())
            .find((item) => item.type === 'timeZoneName')?.value;
        if (part && part !== 'GMT') {
            const match = /^GMT([+-])(\d{1,2})(?::?(\d{2}))?$/.exec(part);
            if (match) {
                const hours = (match[2] ?? '0').padStart(2, '0');
                const minutes = match[3] ?? '00';
                offset =
                    hours === '00' && minutes === '00'
                        ? 'UTC'
                        : `UTC${match[1] ?? '+'}${hours}:${minutes}`;
            }
        }
    } catch {
        offset = 'UTC';
    }
    offsetCache.set(cacheKey, offset);
    return offset;
}

export function timezoneOptions(
    timezones: string[],
    pinned: string[] = [],
): ComboboxOption[] {
    const zones = [...new Set(timezones)];
    const pinnedZones = pinned.filter((zone) => zones.includes(zone));
    const rest = zones.filter((zone) => !pinnedZones.includes(zone));
    const toOption = (zone: string, group?: string): ComboboxOption => {
        const offset = timezoneOffset(zone);
        const city = zone.split('/').slice(1).join('/').replaceAll('_', ' ');
        return {
            value: zone,
            label: zone,
            secondary: city ? `${city} · ${offset}` : undefined,
            searchText: `${city} ${offset} ${offset.replace(':', '')}`,
            group,
        };
    };
    return [
        ...pinnedZones.map((zone) => toOption(zone)),
        ...rest.map((zone) =>
            toOption(
                zone,
                zone.includes('/')
                    ? (zone.split('/')[0] ?? zone).replaceAll('_', ' ')
                    : undefined,
            ),
        ),
    ];
}

/** The one timezone picker: the server IANA list, grouped by region, searchable by city, zone or UTC offset. */
export function TimezoneCombobox({
    id,
    value,
    onValueChange,
    timezones,
    pinned,
    disabled = false,
    invalid = false,
    describedBy,
}: {
    id?: string;
    value: string;
    onValueChange: (value: string) => void;
    /** Server IANA list (DateTimeZone::listIdentifiers) — the only source; the browser list may name zones the server rejects. */
    timezones: string[];
    pinned?: string[];
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}) {
    const options = useMemo(() => {
        // A stored legacy zone stays selectable even when the list omits it.
        const withValue =
            value && !timezones.includes(value)
                ? [value, ...timezones]
                : timezones;
        return timezoneOptions(withValue, pinned);
    }, [timezones, pinned, value]);
    return (
        <Combobox
            id={id}
            options={options}
            value={value}
            onValueChange={onValueChange}
            disabled={disabled}
            invalid={invalid}
            describedBy={describedBy}
        />
    );
}
