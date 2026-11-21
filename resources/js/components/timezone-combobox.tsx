import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { emptySelectValue } from '@/components/ui/select';
import { browserTimezone } from '@/lib/date-time';

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
    const pinnedZones = [...new Set(pinned)].filter((zone) =>
        zones.includes(zone),
    );
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

/** The instance zone a page with a zone field supplies; a page that renders one without it is a bug. */
export function useInstanceTimezone(): string {
    const zone = usePage().props.instanceTimezone;
    if (zone === undefined)
        throw new Error(
            'This page renders a zone field without instanceTimezone.',
        );
    return zone;
}

type TimezoneComboboxProps = {
    id?: string;
    /** Server IANA list (DateTimeZone::listIdentifiers) — the only source; the browser list may name zones the server rejects. */
    timezones: string[];
    pinned?: string[];
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
} & (
    | {
          value: string;
          onValueChange: (value: string) => void;
          defaultZone?: undefined;
      }
    | {
          /** Null is the default: the zone this level inherits. */
          value: string | null;
          onValueChange: (value: string | null) => void;
          /** The inherited zone and where it comes from, named by the first option ("Default: Europe/Berlin (instance)"). */
          defaultZone: { zone: string; source: string };
      }
);

/**
 * The one timezone picker: the server IANA list, grouped by region, searchable by city, zone or UTC offset. On a level
 * that inherits (profile, client, channel) the first option is the default and names its zone and source.
 */
export function TimezoneCombobox(props: TimezoneComboboxProps) {
    const { t } = useTranslation();
    const { id, value, timezones, pinned, defaultZone } = props;
    const options = useMemo(() => {
        // A stored legacy zone stays selectable even when the list omits it.
        const withValue =
            value && !timezones.includes(value)
                ? [value, ...timezones]
                : timezones;
        const zones = timezoneOptions(withValue, pinned);
        return defaultZone === undefined
            ? zones
            : [
                  {
                      value: emptySelectValue,
                      label: t('timezone.default', {
                          zone: defaultZone.zone,
                          source: defaultZone.source,
                      }),
                      secondary: timezoneOffset(defaultZone.zone),
                      searchText: defaultZone.zone,
                  },
                  ...zones,
              ];
    }, [timezones, pinned, value, defaultZone, t]);
    return (
        <Combobox
            id={id}
            options={options}
            value={value ?? emptySelectValue}
            onValueChange={(next) => {
                if (props.defaultZone === undefined) props.onValueChange(next);
                else
                    props.onValueChange(
                        next === emptySelectValue ? null : next,
                    );
            }}
            disabled={props.disabled ?? false}
            invalid={props.invalid ?? false}
            describedBy={props.describedBy}
        />
    );
}

/**
 * The one-off offer of the browser's zone while a level is on its default: "Use it" puts the zone into the draft, and
 * the form's own Save stores it. Hidden when the zone in use already is the browser's, or the server lacks it.
 */
export function BrowserTimezoneHint({
    onDefault,
    current,
    draft,
    timezones,
    onUse,
}: {
    /** The level still uses its default (the instance: UTC; a profile: no own zone). */
    onDefault: boolean;
    /** The zone in use now. */
    current: string;
    draft: string | null;
    timezones: string[];
    onUse: (zone: string) => void;
}) {
    const { t } = useTranslation();
    const zone = browserTimezone();
    if (!onDefault || zone === current || !timezones.includes(zone))
        return null;
    return (
        <Alert
            tone="info"
            action={
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={draft === zone}
                    onClick={() => onUse(zone)}
                >
                    {t('Use it')}
                </Button>
            }
        >
            <AlertDescription>
                {t('Your browser uses {{zone}}. Use it?', { zone })}
            </AlertDescription>
        </Alert>
    );
}
