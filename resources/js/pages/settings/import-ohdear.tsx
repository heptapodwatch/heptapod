import { ActionNoteSlot } from '@/components/action-note-slot';
import { InfoTooltip } from '@/components/info-tooltip';
import { TruncatedText } from '@/components/truncated-text';
import { SectionHeading } from '@/components/section-heading';
import { ImportChannelSection } from '@/components/import-channel-section';
import { ImportOperationBadge } from '@/components/import-operation-badge';
import { ImportAlertTimingSummary } from '@/components/import-alert-timing-summary';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { FormActions, FormSection } from '@/components/form-section';
import InputError, { fieldErrorId } from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { CheckboxField } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { ClientField } from '@/components/client-field';
import {
    clientPagination,
    ListFooter,
    rememberListPageSize,
} from '@/components/list-footer';
import { ListHeader, ListRow } from '@/components/ui/table';
import { useFormat } from '@/hooks/use-format';
import {
    HttpValidationError,
    httpErrorMessage,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { toast } from 'sonner';
import { checkTypes } from '@/lib/check-types';
import { plan as planRoute } from '@/routes/settings/import/ohdear';
import { start as startRoute } from '@/routes/settings/import/ohdear/runs';
import type { CheckKey } from '@/types/monitoring';

type Plan = Heptapod.Data.Import.ImportPlanData & { plan_id: string | null };
type Row = Heptapod.Data.Import.ImportPlanRowData;
// A target is a client id, or `inherit` for a tag or site that follows the next rule.
type Target = string;

const inherit = 'inherit';

/** A site whose tags point to different clients needs an explicit choice; any client, or a new one, resolves it. */
function ConflictSelect({
    id,
    label,
    proposed,
    ...fieldProps
}: {
    id: string;
    label: string;
    /** Names of the clients the conflicting tags point to. */
    proposed: string[];
} & Pick<
    ComponentProps<typeof ClientField>,
    'clients' | 'onValueChange' | 'onCreateClient' | 'disabled'
>) {
    const { t, i18n } = useTranslation();
    return (
        <div className="space-y-1">
            <ClientField
                id={id}
                ariaLabel={label}
                invalid
                describedBy={fieldErrorId(id)}
                placeholder={t('Choose a client')}
                value=""
                {...fieldProps}
            />
            <InputError
                field={id}
                role="status"
                message={t('Tags of this site point to :clients. Choose one.', {
                    clients: new Intl.ListFormat(i18n.language).format(
                        proposed,
                    ),
                })}
            />
        </div>
    );
}

function MappingRow({
    id,
    label,
    meta,
    value,
    onChange,
    onCreateClient,
    clients,
    inheritLabel,
    disabled,
}: {
    id: string;
    label: string;
    meta: string;
    value: Target;
    onChange: (value: Target) => void;
    onCreateClient: (name: string) => void;
    clients: Heptapod.Data.Ui.ClientData[];
    inheritLabel?: string;
    disabled: boolean;
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
            <div className="min-w-0">
                <ClientField
                    id={`${id}-target`}
                    ariaLabel={t('Client for :name', { name: label })}
                    clients={clients}
                    leadingOptions={
                        inheritLabel
                            ? [{ value: inherit, label: inheritLabel }]
                            : []
                    }
                    value={value}
                    onValueChange={onChange}
                    onCreateClient={onCreateClient}
                    disabled={disabled}
                />
            </div>
        </ListRow>
    );
}

export default function ImportOhDear({
    clients: suppliedClients,
    timezones,
    sections,
    plan_page_size,
}: Heptapod.Data.Ui.ImportOhDearPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    // Clients created from a picker join the list, so every other picker offers them too.
    const [clients, setClients] = useState(suppliedClients);
    const internal = clients.find((client) => client.is_internal)!;
    const http = useHttpRequest<
        { ohdear_token: string; sections: Heptapod.Enums.ImportSection[] },
        Plan
    >({
        ohdear_token: '',
        sections,
    });
    const [plan, setPlan] = useState<Plan | null>(null);
    const [planPage, setPlanPage] = useState(1);
    const [planPageSize, setPlanPageSize] = useState(plan_page_size);
    const [failureMessage, setFailureMessage] = useState<string | null>(null);
    // Group mappings are keyed `team:<id>` / `tag:<name>`; every site starts in Internal.
    const [groups, setGroups] = useState<Record<string, Target>>({});
    const [overrides, setOverrides] = useState<Record<string, Target>>({});
    // The picker that opened the client sheet selects the created client.
    const [creating, setCreating] = useState<{
        name: string;
        select: (target: Target) => void;
    } | null>(null);
    const [starting, setStarting] = useState(false);
    const importHttp = useHttpRequest<
        {
            expected: number;
            groups: Record<string, { client_id: number }>;
            overrides: Record<string, { client_id: number }>;
        },
        { url: string }
    >({ expected: 0, groups: {}, overrides: {} });
    const setGroup = (key: string) => (value: Target) =>
        setGroups((current) => ({ ...current, [key]: value }));
    const setOverride = (sourceId: string) => (value: Target) =>
        setOverrides((current) => ({ ...current, [sourceId]: value }));
    const createClientFor =
        (select: (target: Target) => void) => (name: string) =>
            setCreating({ name, select });

    const tags = useMemo(
        () =>
            [
                ...new Set(
                    [
                        ...(plan?.rows ?? []),
                        ...(plan?.channels ?? []).filter(
                            (row) => row.operation !== 'skipped',
                        ),
                    ].flatMap((row) => row.tags),
                ),
            ].sort(),
        [plan],
    );
    const groupTarget = (key: string): Target =>
        groups[key] ?? String(internal.id);
    const targetName = (target: Target): string =>
        clients.find((client) => String(client.id) === target)?.name ??
        internal.name;
    // Distinct clients proposed by the site's assigned tags. The same client from several tags is one candidate.
    const tagCandidates = (row: Row): Target[] => [
        ...new Set(
            row.tags
                .filter(
                    (name) =>
                        groups[`tag:${name}`] !== undefined &&
                        groups[`tag:${name}`] !== inherit,
                )
                .map((name) => groupTarget(`tag:${name}`)),
        ),
    ];
    // A per-site choice wins; then one tag client; then the team. Conflicting tags never resolve silently.
    const resolve = (row: Row): Target | null => {
        const override = overrides[row.source_id];
        if (override !== undefined && override !== inherit) return override;
        const candidates = tagCandidates(row);
        if (candidates.length > 1) return null;
        return candidates[0] ?? groupTarget(`team:${row.team_id}`);
    };
    const automatic = (row: Row): string => {
        const candidates = tagCandidates(row);
        return targetName(candidates[0] ?? groupTarget(`team:${row.team_id}`));
    };

    const submit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFailureMessage(null);
        try {
            const result = await http.post(planRoute.url());
            setPlan(result);
            setPlanPage(1);
            setOverrides({});
        } catch (error: unknown) {
            if (!(error instanceof HttpValidationError)) {
                setFailureMessage(
                    httpErrorMessage(
                        error,
                        t,
                        'The plan could not be loaded. Try again.',
                    ),
                );
            }
        } finally {
            // The token stays server-side only; never keep it in page state after the request.
            http.setData('ohdear_token', '');
        }
    };

    const rows = plan?.rows ?? [];
    const planPagination = clientPagination(
        planPage,
        planPageSize,
        rows.length,
    );
    const visibleRows = rows.slice(
        (planPagination.current_page - 1) * planPagination.per_page,
        planPagination.current_page * planPagination.per_page,
    );
    const changePlanPageSize = (size: number) => {
        setPlanPageSize(size);
        setPlanPage(1);
        rememberListPageSize('import_plan_rows', size);
    };
    const includesSites = plan?.sections.includes('sites') === true;
    const includesChannels = plan?.sections.includes('channels') === true;
    const hasPartialPlan =
        plan !== null && (plan.rows.length > 0 || plan.channels.length > 0);
    const outrightFailure =
        plan !== null && plan.remote_error !== null && !hasPartialPlan;
    const showPlanError =
        http.errors.ohdear_token ??
        failureMessage ??
        (outrightFailure
            ? `${plan.remote_error} ${t('The plan could not be loaded. Try again.')}`
            : null);
    const channelClient = (tags: string[]): string | null => {
        const target = groups[`tag:${tags[0]}`];
        return target &&
            target !== inherit &&
            tags.every((tag) => groups[`tag:${tag}`] === target)
            ? targetName(target)
            : null;
    };
    const hasChannels = (plan?.channels ?? []).some(
        (row) =>
            row.operation !== 'skipped' &&
            (row.level === 'team' || channelClient(row.tags) !== null),
    );
    const planColumns = includesSites
        ? '@3xl/table:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,13rem)]'
        : '@3xl/table:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]';
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
    const payloadTarget = (target: Target) => ({ client_id: Number(target) });
    const runImport = async () => {
        if (!plan?.plan_id) return;
        const groupKeys = [
            ...(includesSites
                ? plan.teams.map((team) => `team:${team.id}`)
                : []),
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
                        payloadTarget(groupTarget(key)),
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
        } catch (error: unknown) {
            setStarting(false);
            if (!(error instanceof HttpValidationError)) {
                toast.warning(httpErrorMessage(error, t));
            }
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
                    title={t('import.sections')}
                    description={t('import.sections_description')}
                >
                    {sections.map((section) => (
                        <CheckboxField
                            key={section}
                            checked={http.data.sections.includes(section)}
                            disabled={http.processing || starting}
                            onCheckedChange={(checked) => {
                                setPlan(null);
                                setFailureMessage(null);
                                http.setData(
                                    'sections',
                                    sections.filter((value) =>
                                        value === section
                                            ? checked === true
                                            : http.data.sections.includes(
                                                  value,
                                              ),
                                    ),
                                );
                            }}
                        >
                            {t(`import.section.${section}`)}
                        </CheckboxField>
                    ))}
                    <InputError
                        role="status"
                        message={
                            http.data.sections.length === 0
                                ? t('import.sections_required')
                                : Object.entries(http.errors).find(([field]) =>
                                      field.startsWith('sections'),
                                  )?.[1]
                        }
                    />
                </FormSection>
                <FormSection
                    title={t('API token')}
                    description={t('Use a read-only Oh Dear API token.')}
                    detail={t(
                        'It is used for this import only: it is kept encrypted for at most 15 minutes between the plan and the import, then it expires. It is never shown or logged.',
                    )}
                >
                    <div className="space-y-1.5">
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
                                aria-describedby={
                                    showPlanError
                                        ? 'ohdear-token-error'
                                        : undefined
                                }
                                disabled={
                                    http.processing ||
                                    starting ||
                                    !http.data.ohdear_token ||
                                    http.data.sections.length === 0
                                }
                            >
                                {http.processing
                                    ? t('Loading plan…')
                                    : t('Show plan')}
                            </Button>
                        </div>
                        <ActionNoteSlot id="ohdear-token-error">
                            {showPlanError && (
                                <InputError message={showPlanError} />
                            )}
                        </ActionNoteSlot>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'This step only reads from Oh Dear. Nothing is imported yet.',
                        )}
                    </p>
                </FormSection>
            </form>
            {plan && !outrightFailure && (
                <ImportAlertTimingSummary rows={plan.rows} />
            )}
            {plan &&
                !outrightFailure &&
                (plan.rows.length > 0 || includesChannels) && (
                    <>
                        {((includesSites && plan.rows.length > 0) ||
                            (includesChannels && tags.length > 0)) && (
                            <FormSection
                                title={t('Clients')}
                                description={t(
                                    includesSites
                                        ? 'Sites without an assigned client go to No client.'
                                        : 'import.channels.assign_tags',
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
                                    {includesSites &&
                                        plan.teams.map((team) => (
                                            <MappingRow
                                                key={team.id}
                                                id={`team-${team.id}`}
                                                label={team.name}
                                                meta={`${t('Team')} · ${t(
                                                    ':count sites',
                                                    {
                                                        count: rows.filter(
                                                            (row) =>
                                                                row.team_id ===
                                                                team.id,
                                                        ).length,
                                                    },
                                                )}`}
                                                value={groupTarget(
                                                    `team:${team.id}`,
                                                )}
                                                onChange={setGroup(
                                                    `team:${team.id}`,
                                                )}
                                                onCreateClient={createClientFor(
                                                    setGroup(`team:${team.id}`),
                                                )}
                                                clients={clients}
                                                disabled={http.processing}
                                            />
                                        ))}
                                    {tags.map((tag) => (
                                        <MappingRow
                                            key={tag}
                                            id={`tag-${tag}`}
                                            label={tag}
                                            meta={`${t('Tag')} · ${t(
                                                ':count sites',
                                                {
                                                    count: rows.filter((row) =>
                                                        row.tags.includes(tag),
                                                    ).length,
                                                },
                                            )}`}
                                            value={
                                                groups[`tag:${tag}`] ?? inherit
                                            }
                                            inheritLabel={t(
                                                includesSites
                                                    ? 'Use the team assignment'
                                                    : 'import.channels.no_assignment',
                                            )}
                                            onChange={setGroup(`tag:${tag}`)}
                                            onCreateClient={createClientFor(
                                                setGroup(`tag:${tag}`),
                                            )}
                                            clients={clients}
                                            disabled={http.processing}
                                        />
                                    ))}
                                </div>
                                {tags.length === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'The sites in this account have no tags.',
                                        )}
                                    </p>
                                )}
                            </FormSection>
                        )}
                        <div className="space-y-3 py-6">
                            <div
                                hidden={rows.length === 0}
                                className="space-y-1"
                            >
                                <SectionHeading id="import-plan">
                                    {t('Plan')}
                                </SectionHeading>
                                <p className="text-sm text-muted-foreground">
                                    {[
                                        includesSites
                                            ? t(':count sites to import', {
                                                  count: imported.length,
                                              })
                                            : t(
                                                  'Import into :count existing sites',
                                                  {
                                                      count: imported.length,
                                                  },
                                              ),
                                        t(':count sites skipped', {
                                            count: skipped.length,
                                        }),
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
                                                .sort((a, b) =>
                                                    a.localeCompare(b),
                                                )
                                                .map((label) => (
                                                    <li key={label}>{label}</li>
                                                ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}
                            {plan.remote_error && (
                                <Alert tone="danger">
                                    <AlertTitle>
                                        {t('Incomplete plan')}
                                    </AlertTitle>
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
                            <div
                                hidden={rows.length === 0}
                                className="@container/table overflow-hidden rounded-[10px] border"
                            >
                                <ListHeader
                                    className={cn(
                                        '@3xl/table:grid',
                                        planColumns,
                                    )}
                                >
                                    <span>{t('Site')}</span>
                                    {includesSites && (
                                        <span>{t('Checks to import')}</span>
                                    )}
                                    <span>{t('Not imported')}</span>
                                    {includesSites && (
                                        <span>{t('Client')}</span>
                                    )}
                                </ListHeader>
                                {visibleRows.map((row) => {
                                    const isSkipped = [
                                        'skipped',
                                        'failed',
                                    ].includes(row.operation);
                                    return (
                                        <ListRow
                                            key={row.source_id}
                                            className={cn(
                                                'items-start',
                                                planColumns,
                                            )}
                                        >
                                            <div className="min-w-0 space-y-1">
                                                <TruncatedText
                                                    label={t('Site')}
                                                    content={
                                                        row.label ?? row.url
                                                    }
                                                >
                                                    <p className="truncate font-medium">
                                                        {row.label ?? row.url}
                                                    </p>
                                                </TruncatedText>
                                                <TruncatedText
                                                    label={t('URL')}
                                                    content={
                                                        row.url ?? undefined
                                                    }
                                                >
                                                    <p className="truncate font-mono text-xs text-muted-foreground">
                                                        {row.url}
                                                    </p>
                                                </TruncatedText>
                                                {row.operation === 'skipped' ? (
                                                    <ImportOperationBadge
                                                        operation={
                                                            row.operation
                                                        }
                                                        label={t(
                                                            row.notices[0] ??
                                                                'import.notice.other',
                                                        )}
                                                    />
                                                ) : (
                                                    <ImportOperationBadge
                                                        operation={
                                                            row.operation
                                                        }
                                                        label={t(
                                                            row.operation ===
                                                                'update' &&
                                                                !includesSites
                                                                ? 'import.update_sections'
                                                                : `import.${row.operation}`,
                                                        )}
                                                    />
                                                )}
                                                {row.maintenance && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            'import.details.maintenance_source',
                                                            row.maintenance,
                                                        )}
                                                    </p>
                                                )}
                                                {row.cron_definitions !==
                                                    null && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            'import.details.cron',
                                                            {
                                                                cron: row.cron_definitions,
                                                            },
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            {includesSites && (
                                                <div className="min-w-0">
                                                    <p className="text-xs text-muted-foreground @3xl/table:hidden">
                                                        {t('Checks to import')}
                                                    </p>
                                                    {row.checks.length +
                                                        row.disabled_checks
                                                            .length ===
                                                    0 ? (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ) : (
                                                        <ul className="flex flex-wrap gap-1">
                                                            {row.checks.map(
                                                                (key) => (
                                                                    <li
                                                                        key={
                                                                            key
                                                                        }
                                                                    >
                                                                        <Badge variant="outline">
                                                                            {checkLabel(
                                                                                key,
                                                                            )}
                                                                        </Badge>
                                                                    </li>
                                                                ),
                                                            )}
                                                            {row.disabled_checks.map(
                                                                (key) => (
                                                                    <li
                                                                        key={
                                                                            key
                                                                        }
                                                                    >
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
                                            )}
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
                                            {includesSites && (
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
                                                                {
                                                                    row.client_name
                                                                }
                                                            </span>
                                                        </p>
                                                    ) : resolve(row) ===
                                                      null ? (
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
                                                            proposed={tagCandidates(
                                                                row,
                                                            ).map(targetName)}
                                                            clients={clients}
                                                            onValueChange={setOverride(
                                                                row.source_id,
                                                            )}
                                                            onCreateClient={createClientFor(
                                                                setOverride(
                                                                    row.source_id,
                                                                ),
                                                            )}
                                                            disabled={
                                                                http.processing
                                                            }
                                                        />
                                                    ) : (
                                                        <ClientField
                                                            id={`site-${row.source_id}-target`}
                                                            ariaLabel={t(
                                                                'Client for :name',
                                                                {
                                                                    name:
                                                                        row.label ??
                                                                        row.url,
                                                                },
                                                            )}
                                                            clients={clients}
                                                            leadingOptions={[
                                                                {
                                                                    value: inherit,
                                                                    label: t(
                                                                        'Automatic: :client',
                                                                        {
                                                                            client: automatic(
                                                                                row,
                                                                            ),
                                                                        },
                                                                    ),
                                                                },
                                                            ]}
                                                            value={
                                                                overrides[
                                                                    row
                                                                        .source_id
                                                                ] ?? inherit
                                                            }
                                                            onValueChange={setOverride(
                                                                row.source_id,
                                                            )}
                                                            onCreateClient={createClientFor(
                                                                setOverride(
                                                                    row.source_id,
                                                                ),
                                                            )}
                                                            disabled={
                                                                http.processing
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            )}
                                        </ListRow>
                                    );
                                })}
                            </div>
                            {rows.length > 0 && (
                                <ListFooter
                                    count={t('import.result.count', {
                                        count: rows.length,
                                        formattedCount: format.number(
                                            rows.length,
                                        ),
                                    })}
                                    pagination={planPagination}
                                    onPageChange={setPlanPage}
                                    onPageSizeChange={changePlanPageSize}
                                />
                            )}
                            {includesChannels && (
                                <ImportChannelSection
                                    rows={plan.channels}
                                    plannedClient={channelClient}
                                />
                            )}
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
                                            : t(
                                                  'Nothing has been imported yet.',
                                              )
                                }
                            >
                                <Button
                                    type="button"
                                    disabled={
                                        blocked ||
                                        starting ||
                                        http.processing ||
                                        (imported.length === 0 && !hasChannels)
                                    }
                                    onClick={runImport}
                                >
                                    {t('Start import')}
                                </Button>
                            </FormActions>
                            <InputError
                                message={Object.values(importHttp.errors)[0]}
                            />
                        </div>
                    </>
                )}
            {creating && (
                <ClientCreateSheet
                    initialName={creating.name}
                    timezones={timezones}
                    onClose={() => setCreating(null)}
                    onCreated={(created) => {
                        setClients((current) => [...current, created]);
                        creating.select(String(created.id));
                        setCreating(null);
                    }}
                />
            )}
        </>
    );
}
