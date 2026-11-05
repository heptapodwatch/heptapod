import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { useFormat } from '@/hooks/use-format';

export const sitemapUrlsPerRunField = 'urls_per_run';

const choices = ['0', '100', '500', '1000', 'max'] as const;
type UrlsPerRun = (typeof choices)[number];

function isUrlsPerRun(value: unknown): value is UrlsPerRun {
    return choices.some((choice) => choice === value);
}

/** The span of a full pass: ⌈URLs / window⌉ runs × the interval, in the largest unit that measures it exactly ("every 4 days", "every day", "every 17 hours"). */
function coverageSpan(
    runs: number,
    intervalSeconds: number | null,
): {
    key: string;
    count: number;
} {
    if (intervalSeconds === null) {
        return { key: 'sitemap.urls_per_run.span.run', count: runs };
    }
    const seconds = runs * intervalSeconds;
    const [unit, size] = (
        [
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
        ] as const
    ).find(([, size]) => seconds % size === 0) ?? ['minute', 60];
    return {
        key: `sitemap.urls_per_run.span.${unit}`,
        count: Math.ceil(seconds / size),
    };
}

/** Sitemap "URLs checked per run": the stored choice plus how long a full pass over the listed URLs takes at the chosen interval. */
export function SitemapUrlsPerRunField({
    value,
    discoveredUrls,
    intervalSeconds,
    error,
    onChange,
}: {
    value: unknown;
    /** Distinct listed URLs of the latest run; null before the check has run. */
    discoveredUrls: number | null;
    /** The draft interval; null while the interval field holds no valid number. */
    intervalSeconds: number | null;
    error?: string;
    onChange: (value: UrlsPerRun) => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const id = useId();
    if (!isUrlsPerRun(value)) {
        throw new Error(`Invalid sitemap URLs per run: ${String(value)}`);
    }
    const size = value === 'max' || value === '0' ? null : Number(value);
    const runs =
        size !== null && discoveredUrls !== null && discoveredUrls > 0
            ? Math.ceil(discoveredUrls / size)
            : null;
    const span = coverageSpan(runs ?? 1, intervalSeconds);
    const help =
        value === '0'
            ? t('sitemap.urls_per_run.help.structure')
            : value === 'max'
              ? t('sitemap.urls_per_run.help.max')
              : runs === null
                ? t('sitemap.urls_per_run.help.unknown', {
                      size: format.number(size ?? 0),
                  })
                : runs === 1
                  ? t('sitemap.urls_per_run.help.every_run', {
                        count: discoveredUrls ?? 0,
                        formattedCount: format.number(discoveredUrls ?? 0),
                    })
                  : t('sitemap.urls_per_run.help.rotation', {
                        count: discoveredUrls ?? 0,
                        formattedCount: format.number(discoveredUrls ?? 0),
                        size: format.number(size ?? 0),
                        span: t(span.key, {
                            count: span.count,
                            formattedCount: format.number(span.count),
                        }),
                    });
    return (
        <div className="space-y-2">
            <Label id={`${id}-label`}>{t('sitemap.urls_per_run')}</Label>
            <RadioCards
                labelledBy={`${id}-label`}
                value={value}
                invalid={!!error}
                describedBy={
                    fieldErrorProps(id, error, `${id}-help`)['aria-describedby']
                }
                options={choices.map((choice) => ({
                    value: choice,
                    label: t(`sitemap.urls_per_run.${choice}`),
                    description: t(
                        `sitemap.urls_per_run.${choice}.description`,
                    ),
                }))}
                onChange={onChange}
            />
            <p
                id={`${id}-help`}
                aria-live="polite"
                className="text-xs text-muted-foreground"
            >
                {help}
            </p>
            <InputError field={id} message={error} />
        </div>
    );
}
