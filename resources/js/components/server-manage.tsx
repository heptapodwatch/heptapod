import { Link as InertiaLink, router, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AbsoluteTime } from '@/components/absolute-time';
import { siteComboboxOptions, siteHost } from '@/components/entity-combobox';
import { EvidenceFacts } from '@/components/evidence-facts';
import { FormSecondaryAction } from '@/components/form-section';
import { InfoTooltip } from '@/components/info-tooltip';
import InputError, {
    fieldErrorId,
    fieldErrorProps,
} from '@/components/input-error';
import { ListFooter } from '@/components/list-footer';
import { StatusDot, statusColor } from '@/components/monitoring';
import { RowActions } from '@/components/row-actions';
import { SectionHeading } from '@/components/section-heading';
import { ScoreRing } from '@/components/score-ring';
import {
    diskThreshold,
    diskUsedPercent,
    memoryUsedPercent,
    ServerStateBadge,
    usePercent,
} from '@/components/server-values';
import { SheetForm } from '@/components/sheet-form';
import { TextLink } from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CheckboxField } from '@/components/ui/checkbox';
import { Combobox, MultiCombobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NumberField,
    type NumberFieldValue,
} from '@/components/ui/number-field';
import { Sheet } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import { statusLabels } from '@/lib/status';
import { cn } from '@/lib/utils';
import { show as serverShow, update } from '@/routes/servers';
import {
    destroy as unlinkSite,
    index as sitesIndex,
    store as linkSites,
} from '@/routes/servers/sites';
import { show as siteShow } from '@/routes/sites';

type ServerRow = Heptapod.Addons.Servers.Data.ServerRowData;
type Thresholds = Heptapod.Addons.Servers.Data.ServerThresholdsData;
type DirectorySite = Heptapod.Contracts.DirectorySiteData;
type Link = Heptapod.Addons.Servers.Data.ServerLinkData;
type Filesystem =
    Heptapod.Addons.Servers.Data.ServerValuesData['filesystems'][number];

type MountLimit = {
    mount: string;
    percent: NumberFieldValue;
    minutes: NumberFieldValue;
};
type SettingsForm = {
    name: string;
    mounts: MountLimit[];
    ignored_mounts: string[];
    memory_percent: NumberFieldValue;
    memory_minutes: NumberFieldValue;
    load_per_core: NumberFieldValue;
    load_minutes: NumberFieldValue;
    no_report_minutes: NumberFieldValue;
};

/** Every mount the server reported or the user ignored: ignored ones are hidden from the values but kept here. */
function knownMounts(server: ServerRow): string[] {
    return [
        ...new Set([
            ...(server.values?.filesystems ?? []).map(
                (filesystem) => filesystem.mount,
            ),
            ...server.ignored_mounts,
        ]),
    ].sort();
}

function settingsOf(server: ServerRow): SettingsForm {
    const { thresholds } = server;
    return {
        name: server.name,
        mounts: knownMounts(server).map((mount) => {
            const custom = thresholds.mounts.find(
                (limit) => limit.mount === mount,
            );
            return {
                mount,
                percent: custom?.percent ?? thresholds.disk_percent,
                minutes: custom?.minutes ?? thresholds.disk_minutes,
            };
        }),
        ignored_mounts: server.ignored_mounts,
        memory_percent: thresholds.memory_percent,
        memory_minutes: thresholds.memory_minutes,
        load_per_core: thresholds.load_per_core,
        load_minutes: thresholds.load_minutes,
        no_report_minutes: thresholds.no_report_minutes,
    };
}

/** "Default" or "Custom" with a reset to the instance default; the default is named while custom. */
function DefaultMark({
    isDefault,
    defaultText,
    onReset,
}: {
    isDefault: boolean;
    defaultText: string;
    onReset: () => void;
}) {
    const { t } = useTranslation();
    return (
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Badge variant="outline">
                {t(
                    isDefault
                        ? 'servers.settings.default'
                        : 'servers.settings.custom',
                )}
            </Badge>
            {!isDefault && (
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    aria-label={t('servers.settings.reset_to', {
                        value: defaultText,
                    })}
                    onClick={onReset}
                >
                    {t('servers.settings.reset')}
                </Button>
            )}
        </span>
    );
}

/** One threshold: its label and Default/Custom mark, then the value and, where it has one, its duration. */
function ThresholdRow({
    id,
    label,
    value,
    unit,
    step,
    min,
    max,
    minutes,
    onChange,
    isDefault,
    defaultText,
    onReset,
    hint,
    errors,
}: {
    id: string;
    label: string;
    value: NumberFieldValue;
    unit: string;
    step?: number;
    min: number;
    max: number;
    minutes?: NumberFieldValue;
    onChange: (
        value: NumberFieldValue,
        minutes: NumberFieldValue | undefined,
    ) => void;
    isDefault: boolean;
    defaultText: string;
    onReset: () => void;
    hint?: string;
    errors: (string | undefined)[];
}) {
    const { t } = useTranslation();
    const error = errors.find(Boolean);
    return (
        <div className="space-y-2 border-t pt-3 first:border-t-0 first:pt-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Label htmlFor={id}>{label}</Label>
                <DefaultMark
                    isDefault={isDefault}
                    defaultText={defaultText}
                    onReset={onReset}
                />
            </div>
            <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 sm:flex">
                <NumberField
                    id={id}
                    className="sm:w-40"
                    unit={unit}
                    step={step}
                    min={min}
                    max={max}
                    value={value}
                    invalid={error !== undefined}
                    describedBy={error ? fieldErrorId(id) : undefined}
                    onValueChange={(next) => onChange(next, minutes)}
                />
                {minutes !== undefined && (
                    <>
                        <span className="text-sm text-muted-foreground">
                            {t('servers.settings.for')}
                        </span>
                        <NumberField
                            id={`${id}-minutes`}
                            aria-label={t('servers.settings.duration', {
                                label,
                            })}
                            className="sm:w-40"
                            unit={t('servers.settings.minutes_unit')}
                            min={1}
                            max={1440}
                            value={minutes}
                            onValueChange={(next) => onChange(value, next)}
                        />
                    </>
                )}
            </div>
            {(hint || !isDefault) && (
                <p className="text-xs text-muted-foreground">
                    {[
                        hint,
                        !isDefault &&
                            t('servers.settings.default_is', {
                                value: defaultText,
                            }),
                    ]
                        .filter(Boolean)
                        .join(' ')}
                </p>
            )}
            <InputError field={id} message={error} />
        </div>
    );
}

/** Settings…: read-only facts, the name, the mounts to show and the alert thresholds of this server. */
export function ServerSettingsSheet({
    open,
    onOpenChange,
    server,
    defaults,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    server: ServerRow;
    defaults: Thresholds;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const percent = usePercent();
    const form = useForm<SettingsForm>(settingsOf(server));
    // Opening shows the server as it is now, not a draft left from an earlier opening.
    useEffect(() => {
        if (open) {
            form.setDefaults(settingsOf(server));
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const { data } = form;
    const set = (patch: Partial<SettingsForm>) =>
        form.setData({ ...data, ...patch });
    const minutes = (value: number) =>
        t('servers.settings.minutes', {
            count: value,
            formattedCount: format.number(value),
        });
    const limitText = (value: number, duration: number) =>
        t('servers.settings.limit', {
            value: percent(value),
            duration: minutes(duration),
        });
    const filesystems = new Map(
        (server.values?.filesystems ?? []).map((filesystem) => [
            filesystem.mount,
            filesystem,
        ]),
    );
    const cores = server.values?.cores ?? null;
    const errors = form.errors as Record<string, string | undefined>;
    const loadPerCore =
        typeof data.load_per_core === 'number' ? data.load_per_core : null;
    return (
        <Sheet
            open={open}
            onOpenChange={(next) => !form.processing && onOpenChange(next)}
        >
            <SheetForm
                title={t('servers.settings.title')}
                description={t('servers.settings.description')}
                aria-busy={form.processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(update.url(server.id), {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    });
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button type="submit" disabled={form.processing}>
                            {t('Save')}
                        </Button>
                    </>
                }
            >
                <EvidenceFacts
                    rows={[
                        {
                            label: t('servers.facts.agent'),
                            value: server.agent_version ?? '—',
                            mono: true,
                        },
                        {
                            label: t('servers.facts.system'),
                            value:
                                [
                                    server.agent_distro,
                                    server.agent_os && server.agent_arch
                                        ? `${server.agent_os}/${server.agent_arch}`
                                        : null,
                                    server.agent_kernel,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || '—',
                        },
                        {
                            label: t('servers.facts.hostname'),
                            value: server.hostname ?? '—',
                            mono: true,
                        },
                        {
                            label: t('servers.facts.ips'),
                            value:
                                server.ips.length > 0 ? (
                                    <span className="flex flex-col">
                                        {server.ips.map((ip) => (
                                            <span key={ip}>{ip}</span>
                                        ))}
                                    </span>
                                ) : (
                                    '—'
                                ),
                            mono: true,
                        },
                        {
                            label: t('servers.last_report'),
                            value: server.last_report_at ? (
                                <AbsoluteTime value={server.last_report_at} />
                            ) : (
                                '—'
                            ),
                        },
                    ]}
                />
                <div className="space-y-1.5">
                    <Label htmlFor="server-settings-name">{t('Name')}</Label>
                    <Input
                        id="server-settings-name"
                        required
                        maxLength={120}
                        value={data.name}
                        {...fieldErrorProps(
                            'server-settings-name',
                            errors.name,
                        )}
                        onChange={(event) => set({ name: event.target.value })}
                    />
                    <InputError
                        field="server-settings-name"
                        message={errors.name}
                    />
                </div>
                {data.mounts.length > 0 && (
                    <section className="space-y-2">
                        <div className="space-y-1">
                            <SectionHeading level={3}>
                                {t('servers.mounts.title')}
                            </SectionHeading>
                            <p className="text-sm text-muted-foreground">
                                {t('servers.mounts.description')}
                            </p>
                        </div>
                        {data.mounts.map(({ mount }) => {
                            const filesystem = filesystems.get(mount);
                            return (
                                <CheckboxField
                                    key={mount}
                                    checked={
                                        !data.ignored_mounts.includes(mount)
                                    }
                                    onCheckedChange={(checked) =>
                                        set({
                                            ignored_mounts:
                                                checked === true
                                                    ? data.ignored_mounts.filter(
                                                          (ignored) =>
                                                              ignored !== mount,
                                                      )
                                                    : [
                                                          ...data.ignored_mounts,
                                                          mount,
                                                      ],
                                        })
                                    }
                                    description={
                                        filesystem &&
                                        t('servers.gauge.disk_meta', {
                                            free: format.bytes(
                                                filesystem.free_bytes,
                                            ),
                                            total: format.bytes(
                                                filesystem.total_bytes,
                                            ),
                                        })
                                    }
                                >
                                    <span className="font-mono text-xs">
                                        {mount}
                                    </span>
                                </CheckboxField>
                            );
                        })}
                    </section>
                )}
                <section className="space-y-3">
                    <div className="space-y-1">
                        <SectionHeading level={3}>
                            {t('servers.settings.thresholds')}
                        </SectionHeading>
                        <p className="text-sm text-muted-foreground">
                            {t('servers.settings.thresholds_description')}
                        </p>
                    </div>
                    {data.mounts.map((limit, index) =>
                        data.ignored_mounts.includes(limit.mount) ? null : (
                            <ThresholdRow
                                key={limit.mount}
                                id={`server-disk-${index}`}
                                label={t('servers.gauge.disk', {
                                    mount: limit.mount,
                                })}
                                value={limit.percent}
                                unit="%"
                                min={1}
                                max={100}
                                minutes={limit.minutes}
                                onChange={(value, duration) =>
                                    set({
                                        mounts: data.mounts.map((other, at) =>
                                            at === index
                                                ? {
                                                      ...other,
                                                      percent: value,
                                                      minutes:
                                                          duration ??
                                                          other.minutes,
                                                  }
                                                : other,
                                        ),
                                    })
                                }
                                isDefault={
                                    limit.percent === defaults.disk_percent &&
                                    limit.minutes === defaults.disk_minutes
                                }
                                defaultText={limitText(
                                    defaults.disk_percent,
                                    defaults.disk_minutes,
                                )}
                                onReset={() =>
                                    set({
                                        mounts: data.mounts.map((other, at) =>
                                            at === index
                                                ? {
                                                      ...other,
                                                      percent:
                                                          defaults.disk_percent,
                                                      minutes:
                                                          defaults.disk_minutes,
                                                  }
                                                : other,
                                        ),
                                    })
                                }
                                errors={[
                                    errors[`mounts.${index}.percent`],
                                    errors[`mounts.${index}.minutes`],
                                ]}
                            />
                        ),
                    )}
                    <ThresholdRow
                        id="server-memory"
                        label={t('servers.gauge.memory')}
                        value={data.memory_percent}
                        unit="%"
                        min={1}
                        max={100}
                        minutes={data.memory_minutes}
                        onChange={(value, duration) =>
                            set({
                                memory_percent: value,
                                memory_minutes: duration ?? null,
                            })
                        }
                        isDefault={
                            data.memory_percent === defaults.memory_percent &&
                            data.memory_minutes === defaults.memory_minutes
                        }
                        defaultText={limitText(
                            defaults.memory_percent,
                            defaults.memory_minutes,
                        )}
                        onReset={() =>
                            set({
                                memory_percent: defaults.memory_percent,
                                memory_minutes: defaults.memory_minutes,
                            })
                        }
                        errors={[errors.memory_percent, errors.memory_minutes]}
                    />
                    <ThresholdRow
                        id="server-load"
                        label={t('servers.gauge.load')}
                        value={data.load_per_core}
                        unit={t('servers.settings.per_core_unit')}
                        step={0.5}
                        min={0.1}
                        max={100}
                        minutes={data.load_minutes}
                        onChange={(value, duration) =>
                            set({
                                load_per_core: value,
                                load_minutes: duration ?? null,
                            })
                        }
                        isDefault={
                            data.load_per_core === defaults.load_per_core &&
                            data.load_minutes === defaults.load_minutes
                        }
                        defaultText={t('servers.settings.load_limit', {
                            factor: format.number(defaults.load_per_core),
                            duration: minutes(defaults.load_minutes),
                        })}
                        onReset={() =>
                            set({
                                load_per_core: defaults.load_per_core,
                                load_minutes: defaults.load_minutes,
                            })
                        }
                        hint={
                            cores === null || loadPerCore === null
                                ? undefined
                                : t('servers.settings.load_hint', {
                                      load: format.number(loadPerCore * cores, {
                                          minimumFractionDigits: 1,
                                          maximumFractionDigits: 1,
                                      }),
                                      count: cores,
                                      formattedCount: format.number(cores),
                                  })
                        }
                        errors={[errors.load_per_core, errors.load_minutes]}
                    />
                    <ThresholdRow
                        id="server-no-report"
                        label={t('servers.settings.no_report_after')}
                        value={data.no_report_minutes}
                        unit={t('servers.settings.minutes_unit')}
                        min={2}
                        max={60}
                        onChange={(value) => set({ no_report_minutes: value })}
                        isDefault={
                            data.no_report_minutes ===
                            defaults.no_report_minutes
                        }
                        defaultText={minutes(defaults.no_report_minutes)}
                        onReset={() =>
                            set({
                                no_report_minutes: defaults.no_report_minutes,
                            })
                        }
                        errors={[errors.no_report_minutes]}
                    />
                </section>
            </SheetForm>
        </Sheet>
    );
}

function SuggestionReason({
    site,
    address,
}: {
    site: DirectorySite;
    address: string;
}) {
    const { t } = useTranslation();
    return (
        <>
            {t('servers.link.reason_before', { host: siteHost(site.url) })}{' '}
            <span className="font-mono">{address}</span>{' '}
            {t('servers.link.reason_after')}
        </>
    );
}

/** "Link site…": the DNS suggestions first (checked), then a search over every other site. Read when it opens. */
export function LinkSitesSheet({
    open,
    onOpenChange,
    server,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    server: ServerRow;
}) {
    const { t } = useTranslation();
    const [declined, setDeclined] = useState<number[]>([]);
    // Picked sites stay chips while later searches return other sites; the server they would move from goes with them.
    const [picked, setPicked] = useState<
        Map<number, { site: DirectorySite; from?: string }>
    >(new Map());
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [query, setQuery] = useState('');
    // The suggestions come with the sheet's first read; each search replaces only the sites to pick from.
    const [suggestions, setSuggestions] = useState<Link['suggestions'] | null>(
        null,
    );
    const [found, setFound] = useState<Link | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        if (!open) return;
        setDeclined([]);
        setPicked(new Map());
        setError(undefined);
        setQuery('');
        setSuggestions(null);
        setFound(null);
        setFailed(false);
    }, [open]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(
            async () => {
                try {
                    const response = await fetch(
                        sitesIndex.url(server.id, {
                            query: query.trim() ? { q: query.trim() } : {},
                        }),
                        {
                            signal: controller.signal,
                            headers: { Accept: 'application/json' },
                        },
                    );
                    if (!response.ok)
                        throw new Error(
                            'Link choices request failed: ' + response.status,
                        );
                    const data: Link = await response.json();
                    setFound(data);
                    if (!query.trim()) setSuggestions(data.suggestions);
                } catch (failure) {
                    if (!controller.signal.aborted) {
                        console.error(failure);
                        setFailed(true);
                    }
                }
            },
            query ? 150 : 0,
        );
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, server.id]);
    const suggested = new Set((suggestions ?? []).map(({ site }) => site.id));
    const movesFrom = new Map(
        (found?.linked_elsewhere ?? []).map((entry) => [
            entry.site_id,
            entry.server_name,
        ]),
    );
    const results = (found?.sites ?? []).filter(
        (site) => !suggested.has(site.id) && !picked.has(site.id),
    );
    const option = (site: DirectorySite, from: string | undefined) => {
        const [base] = siteComboboxOptions([site]);
        if (from === undefined) return base!;
        const secondary = [
            base!.secondary,
            t('servers.link.moves_from', { server: from }),
        ]
            .filter(Boolean)
            .join(' · ');
        return { ...base!, secondary, searchText: secondary };
    };
    const options = [
        ...[...picked.values()].map(({ site, from }) => option(site, from)),
        ...results.map((site) => option(site, movesFrom.get(site.id))),
    ];
    const siteIds = [
        ...(suggestions ?? [])
            .map(({ site }) => site.id)
            .filter((id) => !declined.includes(id)),
        ...picked.keys(),
    ];
    const moving = [...picked.values()].filter(
        ({ from }) => from !== undefined,
    );
    const pick = (values: string[]) =>
        setPicked((current) => {
            const known = new Map([
                ...current,
                ...results.map(
                    (site) =>
                        [
                            site.id,
                            { site, from: movesFrom.get(site.id) },
                        ] as const,
                ),
            ]);
            return new Map(
                values.map((value) => [
                    Number(value),
                    known.get(Number(value))!,
                ]),
            );
        });
    return (
        <Sheet
            open={open}
            onOpenChange={(next) => !processing && onOpenChange(next)}
        >
            <SheetForm
                title={t('servers.link.title', { name: server.name })}
                description={t('servers.link.description')}
                aria-busy={processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    router.post(
                        linkSites.url(server.id),
                        { site_ids: siteIds },
                        {
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                            onError: (errors) => setError(errors.site_ids),
                            onSuccess: () => onOpenChange(false),
                        },
                    );
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={processing}
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={processing || siteIds.length === 0}
                        >
                            {siteIds.length === 0
                                ? t('servers.link.submit_none')
                                : t('servers.link.submit', {
                                      count: siteIds.length,
                                  })}
                        </Button>
                    </>
                }
            >
                {failed ? (
                    <InputError message={t('servers.link.loading_failed')} />
                ) : suggestions === null ? (
                    <div className="space-y-2" aria-hidden>
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="h-9 w-full" />
                    </div>
                ) : (
                    <>
                        {suggestions.length > 0 && (
                            <section className="space-y-2">
                                <SectionHeading
                                    level={3}
                                    className="flex items-center gap-1"
                                >
                                    {t('servers.link.suggested')}
                                    <InfoTooltip>
                                        {t('servers.link.suggested_detail')}
                                    </InfoTooltip>
                                </SectionHeading>
                                {suggestions.map(({ site, address }) => (
                                    <CheckboxField
                                        key={site.id}
                                        checked={!declined.includes(site.id)}
                                        onCheckedChange={(checked) =>
                                            setDeclined((current) =>
                                                checked === true
                                                    ? current.filter(
                                                          (id) =>
                                                              id !== site.id,
                                                      )
                                                    : [...current, site.id],
                                            )
                                        }
                                        description={
                                            <SuggestionReason
                                                site={site}
                                                address={address}
                                            />
                                        }
                                    >
                                        {site.name}
                                        {site.client_name && (
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                · {site.client_name}
                                            </span>
                                        )}
                                    </CheckboxField>
                                ))}
                            </section>
                        )}
                        <div className="space-y-2">
                            <Label id="server-link-sites">
                                {t(
                                    suggestions.length > 0
                                        ? 'servers.link.other_sites'
                                        : 'servers.link.sites',
                                )}
                            </Label>
                            <MultiCombobox
                                labelledBy="server-link-sites"
                                options={options}
                                values={[...picked.keys()].map(String)}
                                onValuesChange={pick}
                                onQueryChange={setQuery}
                                id="server-link-picker"
                                invalid={error !== undefined}
                                describedBy={
                                    error
                                        ? fieldErrorId('server-link-picker')
                                        : undefined
                                }
                                placeholder={t('servers.link.placeholder')}
                            />
                            {moving.map(({ site, from }) => (
                                <p
                                    key={site.id}
                                    className="text-xs text-muted-foreground"
                                >
                                    {t('servers.link.moves', {
                                        site: site.name,
                                        server: from,
                                    })}
                                </p>
                            ))}
                            <InputError
                                field="server-link-picker"
                                message={error}
                            />
                        </div>
                    </>
                )}
            </SheetForm>
        </Sheet>
    );
}

/**
 * The shared link line (alert-routing pattern): state icon, name link, the state word in its colour, then one fact.
 * The server page lists its sites with it; the site Overview names its server the same way.
 */
function LinkLine({ site, fact }: { site: DirectorySite; fact: ReactNode }) {
    const { t } = useTranslation();
    return (
        <p className="flex min-h-4 min-w-0 flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
            <span aria-hidden className="inline-flex">
                <StatusDot state={site.state} />
            </span>
            <TextLink variant="secondary" href={siteShow.url(site.id)}>
                {site.name}
            </TextLink>
            <span aria-hidden>·</span>
            <span style={{ color: statusColor[site.state] }}>
                {t(statusLabels[site.state])}
            </span>
            {fact && (
                <>
                    <span aria-hidden>·</span>
                    <span>{fact}</span>
                </>
            )}
        </p>
    );
}

/** The server page's linked sites, problem sites first, one page at a time, each with Unlink. */
export function LinkedSitesCard({
    server,
    sites,
    pagination,
    onLinkSites,
}: {
    server: ServerRow;
    sites: DirectorySite[];
    pagination: Heptapod.Contracts.PaginationData;
    onLinkSites: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    // The count is every stored link; sites of archived clients keep theirs (for a restore) but are not listed.
    const archived = Math.max(0, server.site_count - pagination.total);
    const archivedNote = archived > 0 && (
        <p className="text-xs text-muted-foreground">
            {t('servers.linked.archived', {
                count: archived,
                formattedCount: format.number(archived),
            })}
        </p>
    );
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {t('servers.linked.title')}
                    {server.site_count > 0 && (
                        <span className="ml-2 font-mono text-xs text-muted-foreground tabular-nums">
                            {format.number(server.site_count)}
                        </span>
                    )}
                </CardTitle>
                <CardDescription>
                    {server.site_count > 0
                        ? t('servers.linked.description')
                        : t('servers.linked.empty')}
                </CardDescription>
            </CardHeader>
            {/* A page beyond the end comes back as the last page, so rows are missing only when none is visible. */}
            {pagination.total > 0 ? (
                <>
                    <ul>
                        {sites.map((site) => (
                            <li key={site.id}>
                                <ListRow className="grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3">
                                    <LinkLine
                                        site={site}
                                        fact={site.client_name}
                                    />
                                    <RowActions
                                        label={site.name}
                                        actions={[
                                            {
                                                label: t('servers.unlink'),
                                                onSelect: () =>
                                                    router.delete(
                                                        unlinkSite.url({
                                                            server: server.id,
                                                            site: site.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    ),
                                            },
                                        ]}
                                    />
                                </ListRow>
                            </li>
                        ))}
                    </ul>
                    <CardContent className="space-y-2">
                        <ListFooter
                            count={t('servers.linked.count', {
                                count: pagination.total,
                                formattedCount: format.number(pagination.total),
                            })}
                            pagination={pagination}
                            only={['linked_sites', 'linked_sites_pagination']}
                        />
                        {archivedNote}
                    </CardContent>
                </>
            ) : (
                <CardContent className="space-y-3">
                    {archivedNote}
                    <Button variant="outline" onClick={onLinkSites}>
                        {t('servers.link.action')}
                    </Button>
                </CardContent>
            )}
        </Card>
    );
}

/** The site Overview's Server card: the server the site runs on, its state and its fullest disk and RAM rings. */
export function SiteServerCard({ server }: { server: ServerRow }) {
    const { t } = useTranslation();
    const percent = usePercent();
    const values = server.values;
    const fullest = values?.filesystems.reduce<Filesystem | undefined>(
        (top, filesystem) =>
            top === undefined ||
            diskUsedPercent(filesystem) > diskUsedPercent(top)
                ? filesystem
                : top,
        undefined,
    );
    const rings = values
        ? [
              ...(fullest
                  ? [
                        {
                            label: t('servers.gauge.disk', {
                                mount: fullest.mount,
                            }),
                            value: diskUsedPercent(fullest),
                            threshold: diskThreshold(
                                server.thresholds,
                                fullest.mount,
                            ),
                            failedWord: t('servers.gauge.almost_full'),
                        },
                    ]
                  : []),
              {
                  label: t('servers.gauge.memory'),
                  value: memoryUsedPercent(values),
                  threshold: server.thresholds.memory_percent,
                  failedWord: t('servers.gauge.low_memory'),
              },
          ]
        : [];
    return (
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle>{t('servers.site.card_title')}</CardTitle>
                <CardAction>
                    <Button asChild variant="ghost">
                        <InertiaLink href={serverShow.url(server.id)}>
                            {t('servers.site.open_server')}
                            <ArrowRight aria-hidden="true" />
                        </InertiaLink>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="flex flex-wrap items-center gap-x-2 text-sm">
                    <span className="font-medium">{server.name}</span>
                    <ServerStateBadge server={server} />
                </p>
                {rings.length > 0 && (
                    <div className="grid grid-cols-2 gap-4">
                        {rings.map((ring) => {
                            const tone =
                                ring.value >= ring.threshold
                                    ? 'failed'
                                    : ring.value >= ring.threshold - 10
                                      ? 'warning'
                                      : 'neutral';
                            return (
                                <div
                                    key={ring.label}
                                    className="grid justify-items-center gap-1.5 text-center"
                                >
                                    <ScoreRing
                                        score={ring.value}
                                        label={ring.label}
                                        text={percent(ring.value)}
                                        tone={tone}
                                        threshold={ring.threshold}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {ring.label}
                                    </span>
                                    {tone !== 'neutral' && (
                                        <span
                                            className={cn(
                                                'text-xs font-medium',
                                                tone === 'failed'
                                                    ? 'text-status-down'
                                                    : 'text-status-warn',
                                            )}
                                        >
                                            {tone === 'failed'
                                                ? ring.failedWord
                                                : t(
                                                      'servers.gauge.near_threshold',
                                                  )}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    {t('servers.site.sites_on_server', {
                        count: server.site_count,
                    })}
                </p>
            </CardContent>
        </Card>
    );
}

/** Site settings: the server the site runs on, or "Not linked"; a draft of the page's one form. */
export function SiteServerField({
    choices,
    value,
    onValueChange,
    disabled,
    error,
}: {
    choices: Heptapod.Addons.Servers.Data.SiteServerChoicesData;
    /** The server id, or 'none' for "Not linked". */
    value: string;
    onValueChange: (value: string) => void;
    disabled?: boolean;
    error?: string;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <Label htmlFor="site-server">{t('servers.site.field')}</Label>
            <Combobox
                id="site-server"
                options={[
                    { value: 'none', label: t('servers.site.not_linked') },
                    ...choices.servers.map((server) => ({
                        value: String(server.id),
                        label: server.name,
                        secondary: server.ips.join(' · ') || undefined,
                    })),
                ]}
                value={value}
                onValueChange={onValueChange}
                disabled={disabled}
                invalid={error !== undefined}
                describedBy={error ? fieldErrorId('site-server') : undefined}
            />
            <p className="text-xs text-muted-foreground">
                {t('servers.site.field_help')}
            </p>
            <InputError field="site-server" message={error} />
        </div>
    );
}
