import { InfoTooltip } from '@/components/info-tooltip';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { ClientMark } from '@/components/client-mark';
import { EmptyState } from '@/components/empty-state';
import { ListFooter } from '@/components/list-footer';
import { useFormat } from '@/hooks/use-format';
import { RowActions } from '@/components/row-actions';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { ListHeader, ListRow } from '@/components/ui/table';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormSecondaryAction } from '@/components/form-section';
import { SheetForm } from '@/components/sheet-form';
import { Sheet } from '@/components/ui/sheet';
import { PageHeader } from '@/components/page-header';
import { Head, usePage } from '@inertiajs/react';
import { Users, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CheckboxField } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { MultiCombobox } from '@/components/ui/combobox';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { useInitials } from '@/hooks/use-initials';
import { formatMonitorTime } from '@/lib/monitor-time';
import {
    data as rosterRoute,
    invite,
    remove,
    resend,
    revoke,
    update,
} from '@/routes/settings/members';

type Roster = Heptapod.Data.Ui.MembersPageData;
type Member = Roster['members'][number];
type Access = { role: string; client_ids: number[] };

function AccessFields({
    prefix,
    clients,
    roles,
    access,
    setAccess,
    errors,
}: {
    prefix: string;
    clients: Roster['clients'];
    roles: string[];
    access: Access;
    setAccess: (access: Access) => void;
    errors: Partial<Record<string, string>>;
}) {
    const { t } = useTranslation();
    const roleError = errors.role;
    // client_ids itself or one of its entries (client_ids.0 …) failed.
    const clientsError =
        errors.client_ids ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('client_ids.'),
        )?.[1];
    const roleField = fieldErrorProps(`${prefix}-role`, roleError);
    const clientsField = fieldErrorProps(
        `${prefix}-clients`,
        clientsError,
        `${prefix}-clients-help`,
    );
    const descriptions: Record<string, string> = {
        Owner: 'members.role.owner',
        Admin: 'members.role.admin',
        Member: 'members.role.member',
    };
    return (
        <div className="space-y-[18px]">
            <div className="space-y-2">
                <Label id={`${prefix}-role-label`}>{t('Role')}</Label>
                <RadioCards
                    labelledBy={`${prefix}-role-label`}
                    invalid={roleField['aria-invalid']}
                    describedBy={roleField['aria-describedby']}
                    value={access.role}
                    onChange={(role) => setAccess({ ...access, role })}
                    options={roles.map((role) => {
                        const description = descriptions[role];
                        if (!description)
                            throw new Error(`Unknown member role: ${role}`);
                        return {
                            value: role,
                            label: t(role),
                            description: t(description),
                        };
                    })}
                />
                <InputError field={`${prefix}-role`} message={roleError} />
            </div>
            <div className="space-y-2">
                <Label id={`${prefix}-clients-label`}>{t('Clients')}</Label>
                {access.role === 'Member' ? (
                    <>
                        {clients.length === 0 &&
                        access.client_ids.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'No clients available. Create a client before inviting a Member.',
                                )}
                            </p>
                        ) : (
                            <MultiCombobox
                                id={`${prefix}-clients`}
                                labelledBy={`${prefix}-clients-label`}
                                invalid={clientsField['aria-invalid']}
                                describedBy={clientsField['aria-describedby']}
                                placeholder={t('Choose clients')}
                                values={access.client_ids.map(String)}
                                onValuesChange={(values) =>
                                    setAccess({
                                        ...access,
                                        client_ids: values.map(Number),
                                    })
                                }
                                options={[
                                    ...clientComboboxOptions(clients).map(
                                        (option, index) =>
                                            clients[index]?.archived
                                                ? {
                                                      ...option,
                                                      secondary: t('Archived'),
                                                      selectedLabel: `${option.label} · ${t('Archived')}`,
                                                  }
                                                : option,
                                    ),
                                    // An assignment to a client this roster no longer lists stays removable.
                                    ...access.client_ids
                                        .filter(
                                            (id) =>
                                                !clients.some(
                                                    (client) =>
                                                        client.id === id,
                                                ),
                                        )
                                        .map((id) => ({
                                            value: String(id),
                                            label: t(
                                                'Unavailable client #:id',
                                                { id },
                                            ),
                                        })),
                                ]}
                            />
                        )}
                        <InputError
                            field={`${prefix}-clients`}
                            message={clientsError}
                        />
                        <p
                            id={`${prefix}-clients-help`}
                            className="text-xs text-muted-foreground"
                        >
                            {t(
                                'Select at least one client. Future clients are not included automatically.',
                            )}{' '}
                            <InfoTooltip>
                                {t(
                                    'Archived assignments remain until you remove them.',
                                )}
                            </InfoTooltip>
                        </p>
                    </>
                ) : (
                    <p className="text-cell text-muted-foreground">
                        {t('All current and future clients')}
                    </p>
                )}
            </div>
        </div>
    );
}

function InviteDialog({
    roster,
    returning,
    onClose,
    onSaved,
}: {
    roster: Roster;
    returning: Member | null;
    onClose: () => void;
    onSaved: (message: string) => Promise<void>;
}) {
    const { t } = useTranslation();
    const { csrfToken, mailLogOnly } = usePage().props;
    if (!csrfToken) throw new Error('Members requires a CSRF token');
    const form = useHttpRequest({
        email: returning?.email ?? '',
        role: 'Member',
        client_ids: [] as number[],
    });
    const [error, setError] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [committed, setCommitted] = useState(false);
    const busy = form.processing || refreshing;
    const save = async (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError('');
        let saved = false;
        try {
            await form.submit(invite(), {
                headers: { 'X-CSRF-TOKEN': csrfToken },
            });
            saved = true;
            setCommitted(true);
            setRefreshing(true);
            await onSaved(
                mailLogOnly
                    ? 'Invitation created; email not delivered: mail is not configured'
                    : 'Invitation sent.',
            );
            onClose();
        } catch (failure: unknown) {
            // Field errors show at their fields.
            if (
                failure instanceof HttpValidationError &&
                Object.keys(failure.errors).length > 0
            )
                return;
            setError(
                saved
                    ? t(
                          'Invitation created, but the roster could not refresh. Close and refresh the roster before retrying.',
                      )
                    : t(
                          'Invitation failed. Check your connection and permissions, then try again.',
                      ),
            );
        } finally {
            setRefreshing(false);
        }
    };
    form.transform((values) => ({
        ...values,
        client_ids: values.role === 'Member' ? values.client_ids : [],
    }));
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <SheetForm
                title={t(
                    returning ? 'Invite back to the team' : 'Invite a teammate',
                )}
                description={t(
                    returning
                        ? 'Access stays disabled until this invitation is accepted.'
                        : 'Invitations expire after seven days.',
                )}
                onSubmit={save}
                noValidate
                contentProps={{ showCloseButton: !busy }}
                footer={
                    <>
                        <FormSecondaryAction disabled={busy} onClick={onClose}>
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button disabled={busy || committed} type="submit">
                            {t(busy ? 'Sending…' : 'Send invite')}
                        </Button>
                    </>
                }
            >
                <fieldset
                    disabled={busy || committed}
                    className="space-y-[18px]"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="invite-email">{t('Email')}</Label>
                        <Input
                            id="invite-email"
                            type="email"
                            value={form.data.email}
                            readOnly={returning !== null}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            {...fieldErrorProps(
                                'invite-email',
                                form.errors.email,
                            )}
                        />
                        <InputError
                            field="invite-email"
                            message={form.errors.email}
                        />
                    </div>
                    <AccessFields
                        prefix="invite"
                        clients={roster.clients}
                        roles={roster.invite_roles}
                        access={form.data}
                        setAccess={(access) =>
                            form.setData({ ...form.data, ...access })
                        }
                        errors={form.errors}
                    />
                </fieldset>
                <InputError message={error} />
            </SheetForm>
        </Sheet>
    );
}

function EditMemberSheet({
    member,
    roster,
    onClose,
    onSaved,
}: {
    member: Member;
    roster: Roster;
    onClose: () => void;
    onSaved: (message: string) => Promise<void>;
}) {
    const { t } = useTranslation();
    const { csrfToken } = usePage().props;
    if (!csrfToken) throw new Error('Members requires a CSRF token');
    const form = useHttpRequest<Access>({
        role: member.role ?? '',
        client_ids: member.client_ids,
    });
    const removal = useHttpRequest();
    const [confirmRemoval, setConfirmRemoval] = useState(false);
    const [confirmRestriction, setConfirmRestriction] = useState(false);
    const legacyAll = member.role === 'Member' && member.client_scope === 'all';
    const [error, setError] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [saved, setSaved] = useState(false);
    const busy = form.processing || removal.processing || refreshing;
    const limited =
        form.data.role === 'Member' &&
        (member.role !== 'Member' || member.client_scope === 'all');
    const removed =
        form.data.role === 'Member' && !limited
            ? member.client_ids.filter(
                  (id) => !form.data.client_ids.includes(id),
              )
            : [];
    const dirty =
        form.data.role !== member.role ||
        form.data.client_ids
            .slice()
            .sort((a, b) => a - b)
            .join(',') !==
            member.client_ids
                .slice()
                .sort((a, b) => a - b)
                .join(',') ||
        (member.client_scope === 'all' && form.data.role === 'Member');
    form.transform((values) => ({
        ...values,
        client_ids: values.role === 'Member' ? values.client_ids : [],
    }));
    /** Resolves true when the change was committed and the roster refreshed; failures are shown in `error`. */
    const save = async (deactivate: boolean): Promise<boolean> => {
        setError('');
        let committed = false;
        try {
            const request = deactivate ? removal : form;
            await request.submit(
                deactivate ? remove(member.id) : update(member.id),
                { headers: { 'X-CSRF-TOKEN': csrfToken } },
            );
            committed = true;
            setSaved(true);
            setRefreshing(true);
            await onSaved(deactivate ? 'Member deactivated.' : 'Access saved.');
            onClose();
            return true;
        } catch (failure: unknown) {
            // Field errors show at their fields; a refused removal has none, so it gets the generic message.
            if (
                !deactivate &&
                failure instanceof HttpValidationError &&
                Object.keys(failure.errors).length > 0
            )
                return false;
            setError(
                committed
                    ? t(
                          'Change saved, but the roster could not refresh. Close and refresh the roster.',
                      )
                    : t(
                          'Change failed. Check your connection and permissions, then try again.',
                      ),
            );
            return false;
        } finally {
            setRefreshing(false);
        }
    };
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <SheetForm
                title={member.name}
                description={member.email}
                onSubmit={(event) => {
                    event.preventDefault();
                    void save(false);
                }}
                contentProps={{ showCloseButton: !busy }}
                footer={
                    <>
                        {member.can_deactivate && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="mr-auto"
                                disabled={busy || saved}
                                onClick={() => setConfirmRemoval(true)}
                            >
                                {t('Remove from team')}
                            </Button>
                        )}
                        <FormSecondaryAction disabled={busy} onClick={onClose}>
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={
                                busy ||
                                saved ||
                                !dirty ||
                                (legacyAll &&
                                    form.data.role === 'Member' &&
                                    !confirmRestriction)
                            }
                        >
                            {t(busy ? 'Saving…' : 'Save access')}
                        </Button>
                    </>
                }
            >
                <fieldset disabled={busy || saved}>
                    <AccessFields
                        prefix="access"
                        clients={roster.clients}
                        roles={member.allowed_roles}
                        access={form.data}
                        setAccess={form.setData}
                        errors={form.errors}
                    />
                </fieldset>
                {member.is_last_owner && (
                    <Alert tone="info">
                        <AlertTitle>
                            {t(
                                'The last active Owner cannot be demoted or removed.',
                            )}
                        </AlertTitle>
                    </Alert>
                )}
                {(removed.length > 0 || limited) && (
                    <Alert tone="warning">
                        <AlertTitle>
                            {limited
                                ? t(
                                      'On save, access will be limited to the selected clients.',
                                  )
                                : t(
                                      'Access will be removed on save: :clients',
                                      {
                                          clients: removed
                                              .map(
                                                  (id) =>
                                                      roster.clients.find(
                                                          (client) =>
                                                              client.id === id,
                                                      )?.name ??
                                                      t(
                                                          'Unavailable client #:id',
                                                          { id },
                                                      ),
                                              )
                                              .join(', '),
                                      },
                                  )}
                        </AlertTitle>
                        <AlertDescription>
                            {t(
                                'Access changes revoke sessions and API tokens. Personal notifications stop for removed clients.',
                            )}
                        </AlertDescription>
                    </Alert>
                )}
                {legacyAll && form.data.role === 'Member' && (
                    <CheckboxField
                        checked={confirmRestriction}
                        disabled={busy || saved}
                        onCheckedChange={(checked) =>
                            setConfirmRestriction(checked === true)
                        }
                    >
                        {t(
                            'Replace legacy all-client access with the selected clients on save.',
                        )}
                    </CheckboxField>
                )}
                <InputError message={error} />
            </SheetForm>
            {/* Removal revokes sign-in, sessions and tokens; returning needs a new invitation, so it is destructive. */}
            <ConfirmDialog
                open={confirmRemoval}
                onOpenChange={setConfirmRemoval}
                destructive
                title={t('Remove :name from the team?', {
                    name: member.name,
                })}
                description={t(
                    'History is retained. Sign-in, sessions, API tokens and live access are revoked. Returning requires a new invitation.',
                )}
                confirmLabel={t('Remove from team')}
                confirmTestId="confirm-remove-member"
                confirmDisabled={saved}
                onConfirm={() => save(true)}
            >
                <InputError message={error} />
            </ConfirmDialog>
        </Sheet>
    );
}

export default function Members({ roster: initialRoster }: { roster: Roster }) {
    const { t } = useTranslation();
    const format = useFormat();
    const { locale, timezone } = usePage().props;
    const initials = useInitials();
    const [roster, setRoster] = useState(initialRoster);
    useEffect(() => setRoster(initialRoster), [initialRoster]);
    const [editing, setEditing] = useState<Member | null>(null);
    const [inviting, setInviting] = useState<{
        returning: Member | null;
    } | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState<number | null>(null);
    const { csrfToken, mailLogOnly } = usePage().props;
    if (!csrfToken) throw new Error('Members requires a CSRF token');
    const reader = useHttpRequest<Record<string, never>, Roster>();
    const action = useHttpRequest();
    const refresh = async (success?: string) => {
        const fresh = await reader.get(rosterRoute.url());
        setRoster(fresh);
        if (success) setMessage(t(success));
    };
    const invitationAction = async (id: number, kind: 'resend' | 'revoke') => {
        setError('');
        setMessage('');
        setPending(id);
        let saved = false;
        try {
            try {
                await action.submit(
                    kind === 'resend' ? resend(id) : revoke(id),
                    { headers: { 'X-CSRF-TOKEN': csrfToken } },
                );
            } catch (failure: unknown) {
                if (!(failure instanceof HttpValidationError)) throw failure;
                setError(
                    t(
                        'Invitation action failed. Refresh the roster and try again.',
                    ),
                );
                return;
            }
            saved = true;
            await refresh(
                kind === 'resend'
                    ? mailLogOnly
                        ? 'Invitation created; email not delivered: mail is not configured'
                        : 'Invitation resent.'
                    : 'Invitation revoked.',
            );
        } catch {
            setError(
                saved
                    ? t(
                          'Invitation changed, but the roster could not refresh. Refresh before retrying.',
                      )
                    : t(
                          'Invitation action failed. Check your connection and permissions, then try again.',
                      ),
            );
        } finally {
            setPending(null);
        }
    };
    const scope = (row: { client_scope: string; client_ids: number[] }) =>
        row.client_scope === 'all'
            ? t('All clients')
            : row.client_ids.length === 0
              ? t('No clients')
              : row.client_ids
                    .map((id) =>
                        roster.clients.find((client) => client.id === id),
                    )
                    .map((client, index) => (
                        <span
                            key={row.client_ids[index]}
                            className="inline-flex items-center gap-2"
                        >
                            {index > 0 && ', '}
                            {client ? (
                                <>
                                    <ClientMark client={client} small />
                                    {client.name}
                                    {client.archived && ` · ${t('Archived')}`}
                                </>
                            ) : (
                                t('Unavailable client #:id', {
                                    id: row.client_ids[index],
                                })
                            )}
                        </span>
                    ));
    const activity = (date: string | null) => {
        if (date === null) return <span>{t('No recorded activity')}</span>;
        const time = formatMonitorTime(date, locale, timezone);
        return (
            <TooltipHint label={t('Last active')} content={time.exact}>
                <time dateTime={date} className="font-mono tabular-nums">
                    {time.label}
                </time>
            </TooltipHint>
        );
    };
    const expires = (date: string, expired: boolean) => {
        const time = formatMonitorTime(date, locale, timezone);
        return (
            <TooltipHint content={time.exact}>
                <time dateTime={date} className="font-mono tabular-nums">
                    {t(expired ? 'Expired :date' : 'Expires :date', {
                        date: time.exact,
                    })}
                </time>
            </TooltipHint>
        );
    };
    const rowClass =
        'xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.5fr)] xl:items-center';
    const busy = pending !== null || reader.processing;
    return (
        <>
            <Head title={t('Members')} />
            <div className="space-y-6">
                <PageHeader
                    title={t('Members')}
                    description={t('pages.members.description')}
                    refresh={{
                        onRefresh: () => {
                            setError('');
                            setMessage('');
                            void refresh().catch(() =>
                                setError(
                                    t(
                                        'Roster could not refresh. Your drafts are unchanged.',
                                    ),
                                ),
                            );
                        },
                        refreshing: reader.processing,
                        disabled: busy,
                    }}
                    actions={
                        <>
                            <Button
                                data-shortcut-create
                                size="sm"
                                disabled={busy}
                                onClick={() => {
                                    setMessage('');
                                    setInviting({ returning: null });
                                }}
                            >
                                <Plus className="size-4" />
                                {t('Invite')}
                            </Button>
                        </>
                    }
                />
                {message && (
                    <p role="status" className="text-cell">
                        {message}
                    </p>
                )}
                <InputError message={error} />
                <div
                    role="table"
                    aria-label={t('Members')}
                    className="@container/table overflow-hidden rounded-lg border"
                >
                    {(roster.members.length > 0 ||
                        roster.invitations.length > 0) && (
                        <ListHeader
                            role="row"
                            className="grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.5fr)] xl:grid"
                        >
                            {['Person', 'Role', 'Clients', 'Last active'].map(
                                (label) => (
                                    <span role="columnheader" key={label}>
                                        {t(label)}
                                    </span>
                                ),
                            )}
                        </ListHeader>
                    )}
                    {roster.members.map((member) => (
                        <ListRow
                            role="row"
                            key={member.id}
                            className={rowClass}
                        >
                            <div
                                role="cell"
                                className="flex min-w-0 items-center gap-2.5"
                            >
                                <Avatar className="size-7 shrink-0">
                                    <AvatarFallback className="text-label">
                                        {initials(member.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0">
                                    <p className="font-medium">{member.name}</p>
                                    <TruncatedText
                                        label={t('Email')}
                                        content={member.email}
                                    >
                                        <p className="truncate text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </TruncatedText>
                                    {member.disabled && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t('Disabled')}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div role="cell">
                                <Badge
                                    variant="secondary"
                                    className="text-xs font-normal"
                                >
                                    {t(member.role ?? 'Unknown role')}
                                </Badge>
                            </div>
                            <p role="cell" className="text-muted-foreground">
                                {scope({
                                    client_scope: member.effective_client_scope,
                                    client_ids: member.client_ids,
                                })}
                            </p>
                            <div
                                role="cell"
                                className="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span className="text-xs text-muted-foreground">
                                    <span className="xl:hidden">
                                        {t('Last active')}:{' '}
                                    </span>
                                    {activity(member.last_active_at)}
                                </span>
                                <RowActions
                                    label={member.name}
                                    actions={[
                                        ...(member.can_edit
                                            ? [
                                                  {
                                                      label: t('Edit'),
                                                      testId: `edit-member-${member.id}`,
                                                      disabled: busy,
                                                      onSelect: () => {
                                                          setMessage('');
                                                          setEditing(member);
                                                      },
                                                  },
                                              ]
                                            : []),
                                        ...(member.can_reinvite
                                            ? [
                                                  {
                                                      label: t('Invite back'),
                                                      testId: `reinvite-member-${member.id}`,
                                                      disabled:
                                                          busy ||
                                                          roster.invitations.some(
                                                              (invitation) =>
                                                                  invitation.email ===
                                                                  member.email,
                                                          ),
                                                      onSelect: () =>
                                                          setInviting({
                                                              returning: member,
                                                          }),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            </div>
                        </ListRow>
                    ))}
                    {roster.invitations.map((invitation) => (
                        <ListRow
                            role="row"
                            key={`invite-${invitation.id}`}
                            className={rowClass}
                        >
                            <div role="cell" className="min-w-0">
                                <TruncatedText
                                    label={t('Email')}
                                    content={invitation.email}
                                >
                                    <p className="truncate font-medium">
                                        {invitation.email}
                                    </p>
                                </TruncatedText>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {invitation.inviter === null
                                        ? t('Inviter unavailable')
                                        : t('Invited by :name', {
                                              name: invitation.inviter,
                                          })}
                                </p>
                            </div>
                            <div role="cell">
                                <Badge
                                    variant="secondary"
                                    className="text-xs font-normal"
                                >
                                    {t(invitation.role)}
                                </Badge>
                            </div>
                            <p role="cell" className="text-muted-foreground">
                                {scope(invitation)}
                            </p>
                            <div role="cell" className="space-y-1 text-xs">
                                <p className="text-muted-foreground">
                                    {t(
                                        invitation.expired
                                            ? 'Expired'
                                            : 'Awaiting acceptance',
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {expires(
                                        invitation.expires_at,
                                        invitation.expired,
                                    )}
                                </p>
                                {invitation.can_manage && (
                                    <RowActions
                                        label={invitation.email}
                                        actions={[
                                            {
                                                label: t(
                                                    pending === invitation.id
                                                        ? 'Working…'
                                                        : 'Resend',
                                                ),
                                                testId: `resend-invitation-${invitation.id}`,
                                                disabled: busy,
                                                onSelect: () =>
                                                    void invitationAction(
                                                        invitation.id,
                                                        'resend',
                                                    ),
                                            },
                                            {
                                                label: t('Revoke'),
                                                testId: `revoke-invitation-${invitation.id}`,
                                                disabled: busy,
                                                destructive: true,
                                                onSelect: () =>
                                                    void invitationAction(
                                                        invitation.id,
                                                        'revoke',
                                                    ),
                                            },
                                        ]}
                                    />
                                )}
                            </div>
                        </ListRow>
                    ))}
                    {roster.members.length === 0 &&
                        roster.invitations.length === 0 && (
                            <EmptyState
                                bare
                                icon={Users}
                                title={t('No members or invitations.')}
                                description={t(
                                    'Invite a teammate to share access to monitored clients.',
                                )}
                            />
                        )}
                </div>
                {(roster.members.length > 0 ||
                    roster.invitations.length > 0) && (
                    <ListFooter
                        count={t('members.list.count', {
                            count:
                                roster.members.length +
                                roster.invitations.length,
                            formattedCount: format.number(
                                roster.members.length +
                                    roster.invitations.length,
                            ),
                        })}
                    />
                )}
            </div>
            {inviting && (
                <InviteDialog
                    roster={roster}
                    returning={inviting.returning}
                    onClose={() => setInviting(null)}
                    onSaved={refresh}
                />
            )}
            {editing && (
                <EditMemberSheet
                    member={editing}
                    roster={roster}
                    onClose={() => setEditing(null)}
                    onSaved={refresh}
                />
            )}
        </>
    );
}
