import { InfoTooltip } from '@/components/info-tooltip';
import { TruncatedText } from '@/components/truncated-text';
import { SectionHeading } from '@/components/section-heading';
import { ImportOperationBadge } from '@/components/import-operation-badge';
import { ImportAlertTimingSummary } from '@/components/import-alert-timing-summary';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormActions, FormSection } from '@/components/form-section';
import InputError, { fieldErrorId } from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EntityCombobox } from '@/components/entity-combobox';
import { ListHeader, ListRow } from '@/components/ui/table';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { checkTypes } from '@/lib/check-types';
import { plan as planRoute } from '@/routes/settings/import/ohdear';
import { start as startRoute } from '@/routes/settings/import/ohdear/runs';
import type { CheckKey } from '@/types/monitoring';

type Plan = Heptapod.Data.Import.ImportPlanData & { plan_id: string | null };
type ImportTarget = { client_id?: number; new_client?: string };
type Row = Heptapod.Data.Import.ImportPlanRowData;
type ClientOption = { id: number; name: string; is_internal: boolean };
// A target is an existing client id (`client:<id>`) or a client created by the import (`new:<key>`).
type Target = string;

const inherit = 'inherit';
const newClient = 'new';

function TargetSelect({
    id,
    label,
    value,
    onChange,
    clients,
    newClients,
    inheritLabel,
    allowNew = false,
}: {
    id: string;
    label: string;
    value: Target;
    onChange: (value: Target) => void;
    clients: ClientOption[];
    newClients: { key: string; name: string }[];
    inheritLabel?: string;
    allowNew?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <EntityCombobox
            id={id}
            ariaLabel={label}
            options={[
                ...(inheritLabel
                    ? [{ value: inherit, label: inheritLabel }]
                    : []),
                ...clients.map((client) => ({
                    value: `client:${client.id}`,
                    label: client.name,
                })),
                ...newClients.map((client) => ({
                    value: `new:${client.key}`,
                    label: t('New client: :name', {
                        name: client.name || t('Unnamed'),
                    }),
                })),
                ...(allowNew
                    ? [{ value: newClient, label: t('New client') }]
                    : []),
            ]}
            value={value}
            onValueChange={onChange}
        />
    );
}

function ConflictSelect({
    id,
    label,
    candidates,
    onChange,
}: {
    id: string;
    label: string;
    candidates: { target: Target; name: string }[];
    onChange: (value: Target) => void;
}) {
    const { t } = useTranslation();
    return (
        <div className="space-y-1">
            <EntityCombobox
                id={id}
                ariaLabel={label}
                invalid
                describedBy={fieldErrorId(id)}
                placeholder={t('Choose a client')}
                options={candidates.map((candidate) => ({
                    value: candidate.target,
                    label: candidate.name,
                }))}
                value=""
                onValueChange={onChange}
            />
            <InputError
                field={id}
                role="status"
                message="Tags of this site point to different clients. Choose one."
            />
        </div>
    );
}

function MappingRow({
    id,
    label,
    meta,
    value,
    name,
    onChange,
    onName,
    clients,
    inheritLabel,
}: {
    id: string;
    label: string;
    meta: string;
    value: Target;
    name: string;
    onChange: (value: Target) => void;
    onName: (name: string) => void;
    clients: ClientOption[];
    inheritLabel?: string;
}) {
    const { t } = useTranslation();
    return (
        <ListRow className="items-center @md/table:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
            <div className="min-w-0">
                <TruncatedText content={label}>
                    <p className="truncate font-medium">{label}</p>
                </TruncatedText>
                <p className="text-xs text-muted-foreground">{meta}</p>
            </div>
            <div className="min-w-0 space-y-2">
                <TargetSelect
                    id={`${id}-target`}
                    label={t('Client for :name', { name: label })}
                    value={value}
                    onChange={onChange}
                    clients={clients}
                    newClients={[]}
                    inheritLabel={inheritLabel}
                    allowNew
                />
                {value === newClient && (
                    <Input
                        aria-label={t('New client name for :name', {
                            name: label,
                        })}
                        value={name}
                        onChange={(event) => onName(event.target.value)}
                    />
                )}
            </div>
        </ListRow>
    );
}

export default function ImportOhDear({ clients }: { clients: ClientOption[] }) {
    const { t } = useTranslation();
    const internal = clients.find((client) => client.is_internal)!;
    const http = useHttpRequest<{ ohdear_token: string }, Plan>({
        ohdear_token: '',
    });
    const [plan, setPlan] = useState<Plan | null>(null);
    const [failure, setFailure] = useState(false);
    // Group mappings are keyed `team:<id>` / `tag:<name>`; every site starts in Internal.
    const [groups, setGroups] = useState<Record<string, Target>>({});
    const [names, setNames] = useState<Record<string, string>>({});
    const [overrides, setOverrides] = useState<Record<string, Target>>({});
    const [resetCount, setResetCount] = useState(0);
    const [starting, setStarting] = useState(false);
    const importHttp = useHttpRequest<
        {
            expected: number;
            groups: Record<string, ImportTarget>;
            overrides: Record<string, ImportTarget>;
        },
        { url: string }
    >({ expected: 0, groups: {}, overrides: {} });
    // A new client starts with the team or tag name, so it is never unnamed.
    // Dropping a proposed new client returns the sites that chose it to Automatic, and says so.
    const assign = (key: string, sourceName: string, value: Target) => {
        const dropped = Object.entries(overrides).filter(
            ([, target]) => target === `new:${key}`,
        );
        if (groups[key] === newClient && value !== newClient) {
            setResetCount(dropped.length);
            if (dropped.length > 0)
                setOverrides((current) =>
                    Object.fromEntries(
                        Object.entries(current).filter(
                            ([, target]) => target !== `new:${key}`,
                        ),
                    ),
                );
        } else {
            setResetCount(0);
        }
        setGroups((current) => ({ ...current, [key]: value }));
        setNames((current) =>
            key in current ? current : { ...current, [key]: sourceName },
        );
    };

    const tags = useMemo(
        () =>
            [...new Set((plan?.rows ?? []).flatMap((row) => row.tags))].sort(),
        [plan],
    );
    const groupTarget = (key: string): Target =>
        groups[key] ?? `client:${internal.id}`;
    const newClients = Object.entries(groups)
        .filter(([, target]) => target === newClient)
        .map(([key]) => ({ key, name: names[key] ?? '' }));
    const targetName = (target: Target, key?: string): string => {
        if (target === newClient && key) return names[key] || t('Unnamed');
        if (target.startsWith('new:'))
            return names[target.slice(4)] || t('Unnamed');
        return (
            clients.find((client) => `client:${client.id}` === target)?.name ??
            internal.name
        );
    };
    // A group target is either an existing client or the new client proposed by that group (`new:<group key>`).
    const resolvedGroup = (key: string): Target => {
        const target = groupTarget(key);
        return target === newClient ? `new:${key}` : target;
    };
    // Distinct clients proposed by the site's assigned tags. The same client from several tags is one candidate.
    const tagCandidates = (row: Row): Target[] => [
        ...new Set(
            row.tags
                .filter(
                    (name) =>
                        groups[`tag:${name}`] !== undefined &&
                        groups[`tag:${name}`] !== inherit,
                )
                .map((name) => resolvedGroup(`tag:${name}`)),
        ),
    ];
    // A per-site choice wins; then one tag client; then the team. Conflicting tags never resolve silently.
    const resolve = (row: Row): Target | null => {
        const override = overrides[row.source_id];
        if (override !== undefined && override !== inherit) return override;
        const candidates = tagCandidates(row);
        if (candidates.length > 1) return null;
        return candidates[0] ?? resolvedGroup(`team:${row.team_id}`);
    };
    const automatic = (row: Row): string => {
        const candidates = tagCandidates(row);
        return targetName(
            candidates[0] ?? resolvedGroup(`team:${row.team_id}`),
        );
    };

    const submit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFailure(false);
        try {
            const result = await http.post(planRoute.url());
            setPlan(result);
            setOverrides({});
            setResetCount(0);
        } catch (error: unknown) {
            if (!(error instanceof HttpValidationError)) setFailure(true);
        } finally {
            // The token stays server-side only; never keep it in page state after the request.
            http.setData('ohdear_token', '');
        }
    };

    const rows = plan?.rows ?? [];
    const imported = rows.filter((row) =>
        ['create', 'update', 'partial'].includes(row.operation),
    );
    const skipped = rows.filter((row) =>
        ['skipped', 'failed'].includes(row.operation),
    );
    // Reasons shared by every importable site are shown once above the table, not per row.
    const common =
        imported.length > 1
            ? imported[0]!.notices.filter((key) =>
                  imported.every((row) => row.notices.includes(key)),
              )
            : [];
    const rowNotices = (row: Row) =>
        row.notices
            .filter((key) => !common.includes(key))
            .map((key) => t(key))
            .sort((a, b) => a.localeCompare(b));
    const notImported =
        common.length +
        imported.reduce((count, row) => count + rowNotices(row).length, 0);
    const conflicts = imported.filter(
        (row) => row.site_id === null && resolve(row) === null,
    );
    // The server creates a runnable plan only when every site was read; otherwise the plan must be shown again.
    const unreadSites = !plan?.remote_error && !plan?.plan_id;
    const blocked = !!plan?.remote_error || unreadSites || conflicts.length > 0;
    // The server re-resolves the same choices against its stored plan and rejects anything unresolved.
    const payloadTarget = (target: Target): ImportTarget =>
        target.startsWith('client:')
            ? { client_id: Number(target.slice('client:'.length)) }
            : { new_client: targetName(target) };
    const runImport = async () => {
        if (!plan?.plan_id) return;
        const groupKeys = [
            ...(plan.teams ?? []).map((team) => `team:${team.id}`),
            ...tags
                .map((tag) => `tag:${tag}`)
                .filter(
                    (key) =>
                        groups[key] !== undefined && groups[key] !== inherit,
                ),
        ];
        const overrideEntries = Object.entries(overrides).filter(
            ([, target]) => target !== inherit,
        );
        setStarting(true);
        try {
            importHttp.transform(() => ({
                expected: rows.length,
                groups: Object.fromEntries(
                    groupKeys.map((key) => [
                        key,
                        payloadTarget(resolvedGroup(key)),
                    ]),
                ),
                overrides: Object.fromEntries(
                    overrideEntries.map(([sourceId, target]) => [
                        sourceId,
                        payloadTarget(target),
                    ]),
                ),
            }));
            const result = await importHttp.post(startRoute.url(plan.plan_id));
            router.visit(result.url);
        } catch {
            setStarting(false);
        }
    };
    const checkLabel = (key: string) =>
        key in checkTypes ? t(checkTypes[key as CheckKey].label) : key;

    return (
        <>
            <Head title={t('Import')} />
            <PageHeader
                title={t('Import')}
                description={t('pages.import.description')}
            />
            <form noValidate onSubmit={submit} aria-busy={http.processing}>
                <FormSection
                    title={t('API token')}
                    description={t('Use a read-only Oh Dear API token.')}
                    detail={t(
                        'It is used for this import only: it is kept encrypted for at most 15 minutes between the plan and the import, then it expires. It is never shown or logged.',
                    )}
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="min-w-0 flex-1 basis-64 space-y-1.5">
                            <Label htmlFor="ohdear-token">
                                {t('Oh Dear API token')}
                            </Label>
                            <Input
                                id="ohdear-token"
                                type="password"
                                autoComplete="off"
                                spellCheck={false}
                                value={http.data.ohdear_token}
                                onChange={(event) =>
                                    http.setData(
                                        'ohdear_token',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!http.errors.ohdear_token}
                                aria-describedby={
                                    http.errors.ohdear_token
                                        ? 'ohdear-token-error'
                                        : undefined
                                }
                                className="font-mono"
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                http.processing || !http.data.ohdear_token
                            }
                        >
                            {t('Show plan')}
                        </Button>
                    </div>
                    <InputError
                        id="ohdear-token-error"
                        message={http.errors.ohdear_token}
                    />
                    <InputError
                        message={
                            failure ||
                            (plan?.remote_error && plan.rows.length === 0)
                                ? (plan?.remote_error ??
                                  'The plan could not be loaded. Try again.')
                                : null
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'This step only reads from Oh Dear. Nothing is imported yet.',
                        )}
                    </p>
                </FormSection>
            </form>
            {plan && <ImportAlertTimingSummary rows={plan.rows} />}
            {plan && plan.rows.length > 0 && (
                <>
                    <FormSection
                        title={t('Clients')}
                        description={t(
                            'Sites without an assigned client go to No client.',
                        )}
                        detail={t(
                            'A tag assignment wins over the team assignment; a choice per site wins over both.',
                        )}
                    >
                        <div className="@container/table overflow-hidden rounded-[10px] border">
                            <ListHeader className="@md/table:grid @md/table:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
                                <span>{t('Oh Dear team or tag')}</span>
                                <span>{t('Client')}</span>
                            </ListHeader>
                            {plan.teams.map((team) => (
                                <MappingRow
                                    key={team.id}
                                    id={`team-${team.id}`}
                                    label={team.name}
                                    meta={`${t('Team')} · ${t(':count sites', {
                                        count: rows.filter(
                                            (row) => row.team_id === team.id,
                                        ).length,
                                    })}`}
                                    value={groupTarget(`team:${team.id}`)}
                                    name={names[`team:${team.id}`] ?? team.name}
                                    onChange={(value) =>
                                        assign(
                                            `team:${team.id}`,
                                            team.name,
                                            value,
                                        )
                                    }
                                    onName={(name) =>
                                        setNames((current) => ({
                                            ...current,
                                            [`team:${team.id}`]: name,
                                        }))
                                    }
                                    clients={clients}
                                />
                            ))}
                            {tags.map((tag) => (
                                <MappingRow
                                    key={tag}
                                    id={`tag-${tag}`}
                                    label={tag}
                                    meta={`${t('Tag')} · ${t(':count sites', {
                                        count: rows.filter((row) =>
                                            row.tags.includes(tag),
                                        ).length,
                                    })}`}
                                    value={groups[`tag:${tag}`] ?? inherit}
                                    inheritLabel={t('Use the team assignment')}
                                    name={names[`tag:${tag}`] ?? tag}
                                    onChange={(value) =>
                                        assign(`tag:${tag}`, tag, value)
                                    }
                                    onName={(name) =>
                                        setNames((current) => ({
                                            ...current,
                                            [`tag:${tag}`]: name,
                                        }))
                                    }
                                    clients={clients}
                                />
                            ))}
                        </div>
                        {tags.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                {t('The sites in this account have no tags.')}
                            </p>
                        )}
                    </FormSection>
                    <section
                        aria-labelledby="import-plan"
                        className="space-y-3 py-6"
                    >
                        <div className="space-y-1">
                            <SectionHeading id="import-plan">
                                {t('Plan')}
                            </SectionHeading>
                            <p className="text-sm text-muted-foreground">
                                {[
                                    t(':count sites to import', {
                                        count: imported.length,
                                    }),
                                    t(
                                        ':count existing or unsupported sites skipped',
                                        {
                                            count: skipped.length,
                                        },
                                    ),
                                    t(':count items not imported', {
                                        count: notImported,
                                    }),
                                    t('Nothing has been imported yet.'),
                                ].join(' · ')}
                            </p>
                        </div>
                        {common.length > 0 && (
                            <Alert tone="info">
                                <AlertTitle>
                                    {t('Not imported for any site')}
                                </AlertTitle>
                                <AlertDescription>
                                    <ul className="list-disc space-y-1 pl-4 text-xs">
                                        {common
                                            .map((key) => t(key))
                                            .sort((a, b) => a.localeCompare(b))
                                            .map((label) => (
                                                <li key={label}>{label}</li>
                                            ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        )}
                        {plan.remote_error && (
                            <Alert tone="danger">
                                <AlertTitle>{t('Incomplete plan')}</AlertTitle>
                                <AlertDescription>
                                    <p>
                                        {t(
                                            'Import stays unavailable until a complete plan loads. Show the plan again to start the import.',
                                        )}{' '}
                                        <InfoTooltip>
                                            {t(
                                                'Oh Dear stopped answering before all sites were read; only the sites below were read.',
                                            )}
                                        </InfoTooltip>
                                    </p>
                                    <p className="font-mono text-xs">
                                        {plan.remote_error}
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}
                        {resetCount > 0 && (
                            <Alert tone="info">
                                <AlertTitle>
                                    {t(
                                        ':count site choices returned to Automatic because their new client was removed.',
                                        { count: resetCount },
                                    )}
                                </AlertTitle>
                            </Alert>
                        )}
                        <div className="@container/table overflow-hidden rounded-[10px] border">
                            <ListHeader className="@3xl/table:grid @3xl/table:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,13rem)]">
                                <span>{t('Site')}</span>
                                <span>{t('Checks to import')}</span>
                                <span>{t('Not imported')}</span>
                                <span>{t('Client')}</span>
                            </ListHeader>
                            {rows.map((row) => {
                                const isSkipped = [
                                    'skipped',
                                    'failed',
                                ].includes(row.operation);
                                return (
                                    <ListRow
                                        key={row.source_id}
                                        className="items-start @3xl/table:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,13rem)]"
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <TruncatedText
                                                label={t('Site')}
                                                content={row.label ?? row.url}
                                            >
                                                <p className="truncate font-medium">
                                                    {row.label ?? row.url}
                                                </p>
                                            </TruncatedText>
                                            <TruncatedText
                                                label={t('URL')}
                                                content={row.url ?? undefined}
                                            >
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {row.url}
                                                </p>
                                            </TruncatedText>
                                            {row.operation === 'skipped' ? (
                                                <ImportOperationBadge
                                                    operation={row.operation}
                                                    label={t(
                                                        row.notices[0] ??
                                                            'import.notice.other',
                                                    )}
                                                />
                                            ) : (
                                                <ImportOperationBadge
                                                    operation={row.operation}
                                                    label={t(
                                                        `import.${row.operation}`,
                                                    )}
                                                />
                                            )}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted-foreground @3xl/table:hidden">
                                                {t('Checks to import')}
                                            </p>
                                            {row.checks.length +
                                                row.disabled_checks.length ===
                                            0 ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                <ul className="flex flex-wrap gap-1">
                                                    {row.checks.map((key) => (
                                                        <li key={key}>
                                                            <Badge variant="outline">
                                                                {checkLabel(
                                                                    key,
                                                                )}
                                                            </Badge>
                                                        </li>
                                                    ))}
                                                    {row.disabled_checks.map(
                                                        (key) => (
                                                            <li key={key}>
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-muted-foreground"
                                                                >
                                                                    {`${checkLabel(key)} · ${t('Off')}`}
                                                                </Badge>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted-foreground @3xl/table:hidden">
                                                {t('Not imported')}
                                            </p>
                                            {isSkipped ||
                                            rowNotices(row).length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                <ul className="list-disc space-y-1 pl-4 text-xs break-words">
                                                    {rowNotices(row).map(
                                                        (label) => (
                                                            <li key={label}>
                                                                {label}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </div>
                                        <div className="min-w-0">
                                            {isSkipped ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : row.site_id !== null ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'Already exists, client unchanged',
                                                    )}
                                                    {': '}
                                                    <span className="text-foreground">
                                                        {row.client_name}
                                                    </span>
                                                </p>
                                            ) : resolve(row) === null ? (
                                                <ConflictSelect
                                                    id={`site-${row.source_id}-target`}
                                                    label={t(
                                                        'Client for :name',
                                                        {
                                                            name:
                                                                row.label ??
                                                                row.url,
                                                        },
                                                    )}
                                                    candidates={tagCandidates(
                                                        row,
                                                    ).map((target) => ({
                                                        target,
                                                        name: targetName(
                                                            target,
                                                        ),
                                                    }))}
                                                    onChange={(value) =>
                                                        setOverrides(
                                                            (current) => ({
                                                                ...current,
                                                                [row.source_id]:
                                                                    value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <TargetSelect
                                                    id={`site-${row.source_id}-target`}
                                                    label={t(
                                                        'Client for :name',
                                                        {
                                                            name:
                                                                row.label ??
                                                                row.url,
                                                        },
                                                    )}
                                                    value={
                                                        overrides[
                                                            row.source_id
                                                        ] ?? inherit
                                                    }
                                                    onChange={(value) =>
                                                        setOverrides(
                                                            (current) => ({
                                                                ...current,
                                                                [row.source_id]:
                                                                    value,
                                                            }),
                                                        )
                                                    }
                                                    clients={clients}
                                                    newClients={newClients}
                                                    inheritLabel={t(
                                                        'Automatic: :client',
                                                        {
                                                            client: automatic(
                                                                row,
                                                            ),
                                                        },
                                                    )}
                                                />
                                            )}
                                        </div>
                                    </ListRow>
                                );
                            })}
                        </div>
                        <FormActions
                            status={
                                plan.remote_error
                                    ? t('Incomplete plan')
                                    : unreadSites
                                      ? t(
                                            'Some sites could not be read. Show the plan again.',
                                        )
                                      : conflicts.length > 0
                                        ? t(
                                              'Choose a client for every site with conflicting tags before the import.',
                                          )
                                        : t('Nothing has been imported yet.')
                            }
                        >
                            <Button
                                type="button"
                                disabled={
                                    blocked || starting || imported.length === 0
                                }
                                onClick={runImport}
                            >
                                {t('Start import')}
                            </Button>
                        </FormActions>
                        <InputError
                            message={Object.values(importHttp.errors)[0]}
                        />
                    </section>
                </>
            )}
        </>
    );
}
