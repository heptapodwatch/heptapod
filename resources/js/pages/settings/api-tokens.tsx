import { ActionNoteSlot } from '@/components/action-note-slot';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { SectionHeading } from '@/components/section-heading';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ClientMark } from '@/components/client-mark';
import {
    EntityCombobox,
    clientComboboxOptions,
} from '@/components/entity-combobox';
import { EmptyState } from '@/components/empty-state';
import { ListHeader, ListRow } from '@/components/ui/table';
import { FormSecondaryAction } from '@/components/form-section';
import { SheetForm } from '@/components/sheet-form';
import { Sheet } from '@/components/ui/sheet';
import { PageHeader } from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { KeyRound, Copy, Plus } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EvidenceBlock } from '@/components/evidence-block';
import { ShownOnceSecret } from '@/components/shown-once-secret';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { DateTimeField } from '@/components/ui/date-time-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormat } from '@/hooks/use-format';
import { ListFooter } from '@/components/list-footer';
import {
    HttpValidationError,
    httpErrorMessage,
    useHttpRequest,
} from '@/hooks/use-http-request';
import { zonedInstant } from '@/lib/date-time';
import {
    AbilityMatrix,
    TokenGrant,
    abilityLabel,
} from '@/components/token-abilities';
import { TooltipHint, TruncatedText } from '@/components/truncated-text';
import { Badge } from '@/components/ui/badge';
import { AbsoluteTime } from '@/components/absolute-time';
import { data as readData, destroy, store } from '@/routes/settings/api-tokens';

type TokenPage = Heptapod.Data.Ui.ApiTokenSettingsData;
type Token = TokenPage['tokens'][number];
type StartingPoint =
    | 'read-everything'
    | 'deploy-automation'
    | 'full-access'
    | 'empty';
type Draft = {
    name: string;
    abilities: string[];
    reach: '' | 'client' | 'all';
    client_id: string;
    expiry: '30-days' | 'custom' | 'none';
    expires_at: string;
};
type Disclosure = {
    value: string;
    name: string;
    abilities: string[];
    client: string | null;
    expires_at: string | null;
};
const dialogClass =
    'max-h-[calc(100dvh_-_2rem)] max-w-[calc(100vw_-_2rem)] overflow-y-auto sm:max-w-[480px]';

function CreateToken({
    page,
    onCreated,
    onClose,
}: {
    page: TokenPage;
    onCreated: (secret: Disclosure) => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const startingPoints: Record<StartingPoint, string[] | null> = {
        ...page.starting_points,
        empty: [],
    };
    // Unmount the form after issuance: the request also holds its response in component memory.
    const form = useHttpRequest<
        Draft,
        { token: string; expires_at: string | null }
    >({
        name: '',
        abilities: page.starting_points['read-everything'],
        reach: '',
        client_id: '',
        expiry: '30-days',
        expires_at: '',
    });
    // The starting point only fills the checklist; it shows as chosen while the checklist still matches it.
    const startingPoint =
        (Object.keys(startingPoints) as StartingPoint[]).find(
            (key) =>
                JSON.stringify(startingPoints[key]) ===
                JSON.stringify(form.data.abilities),
        ) ?? '';
    // The expiry is entered as a wall time in the account timezone, like every other date on this page.
    const customExpiresAt = form.data.expires_at
        ? zonedInstant(form.data.expires_at, page.timezone)
        : null;
    const [failure, setFailure] = useState(false);
    const [expiryInvalid, setExpiryInvalid] = useState(false);
    const [reachError, setReachError] = useState<string | null>(null);
    const mounted = useRef(true);
    const focus = useRef<string | undefined>(undefined);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            form.cancel();
        };
    }, [form.cancel]);
    useEffect(() => {
        if (!form.processing && focus.current) {
            document.getElementById(focus.current)?.focus();
            focus.current = undefined;
        }
    }, [form.processing, form.errors, reachError, expiryInvalid]);
    const abilitiesError =
        form.errors.abilities ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('abilities.'),
        )?.[1];
    const submit = async (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Client access is a deliberate choice, never a silent default.
        if (
            form.data.reach === '' ||
            (form.data.reach === 'client' && !form.data.client_id)
        ) {
            setReachError(
                form.data.reach === ''
                    ? 'Choose which clients the token can reach.'
                    : 'Choose a client.',
            );
            focus.current =
                form.data.reach === '' ? 'token-reach' : 'token-client';
            return;
        }
        // Unparsable or missing custom expiry fails loud; never send a stale or empty value.
        if (
            form.data.expiry === 'custom' &&
            (expiryInvalid || !customExpiresAt)
        ) {
            setExpiryInvalid(true);
            focus.current = 'token-expiry';
            return;
        }
        // The server counts the 30 days from its own clock; the browser clock never sets a lifetime.
        form.transform((draft) => ({
            name: draft.name,
            abilities: draft.abilities,
            client_id:
                draft.reach === 'client' ? Number(draft.client_id) : null,
            expires_at: draft.expiry === 'custom' ? customExpiresAt : null,
            expires_in_days: draft.expiry === '30-days' ? 30 : null,
        }));
        try {
            const result = await form.post(store.url(), {
                onError: (errors) => {
                    const ids: Record<string, string> = {
                        name: 'token-name',
                        abilities: 'token-abilities',
                        client_id: 'token-client',
                        expires_at: 'token-expiry',
                    };
                    const first = Object.keys(errors)[0]?.split('.')[0];
                    focus.current = first ? ids[first] : undefined;
                },
            });
            if (mounted.current)
                onCreated({
                    value: result.token,
                    name: form.data.name,
                    abilities: form.data.abilities,
                    client:
                        form.data.reach === 'client'
                            ? page.clients.find(
                                  (client) =>
                                      client.id === Number(form.data.client_id),
                              )!.name
                            : null,
                    expires_at: result.expires_at,
                });
        } catch (error: unknown) {
            // Field errors are already shown. A lost issuance response is ambiguous: require a metadata check before another attempt.
            if (error instanceof HttpValidationError) return;
            if (mounted.current) setFailure(true);
        }
    };
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t('Create token')}
                description={t('A token acts with your current rights.')}
                detail={t(
                    'Its client constraint never expands after access loss.',
                )}
                onSubmit={submit}
                contentProps={{
                    onEscapeKeyDown: (event) => {
                        if (form.processing) event.preventDefault();
                    },
                    onPointerDownOutside: (event) => {
                        if (form.processing) event.preventDefault();
                    },
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={form.processing || failure}
                        >
                            {t('Create token')}
                        </Button>
                    </>
                }
            >
                <InputError
                    message={
                        failure
                            ? 'Token request failed. Close and reload the list to check whether it was created before trying again.'
                            : null
                    }
                />
                <div className="space-y-1.5">
                    <Label htmlFor="token-name">{t('Name')}</Label>
                    <Input
                        id="token-name"
                        name="name"
                        required
                        value={form.data.name}
                        disabled={form.processing}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        aria-describedby="token-name-error"
                    />
                    <InputError
                        id="token-name-error"
                        message={form.errors.name}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label id="token-start-label">{t('Start from')}</Label>
                    <RadioGroup
                        id="token-start"
                        aria-labelledby="token-start-label"
                        value={startingPoint}
                        disabled={form.processing}
                        onValueChange={(value) =>
                            form.setData(
                                'abilities',
                                startingPoints[value as StartingPoint] ?? [],
                            )
                        }
                    >
                        <RadioGroupItem value="read-everything">
                            {t('Read everything')}
                        </RadioGroupItem>
                        {startingPoints['deploy-automation'] && (
                            <RadioGroupItem value="deploy-automation">
                                {t('Deploy automation')}
                            </RadioGroupItem>
                        )}
                        <RadioGroupItem value="full-access">
                            {t('Full access')}
                        </RadioGroupItem>
                        <RadioGroupItem value="empty">
                            {t('Start empty')}
                        </RadioGroupItem>
                    </RadioGroup>
                </div>
                <div
                    id="token-abilities"
                    tabIndex={-1}
                    role="group"
                    aria-labelledby="token-abilities-label"
                    {...fieldErrorProps('token-abilities', abilitiesError)}
                    className="space-y-1.5 outline-none"
                >
                    <p
                        id="token-abilities-label"
                        className="text-sm font-medium"
                    >
                        {t('Permissions')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('The checked permissions are the grant.')}
                    </p>
                    <AbilityMatrix
                        available={page.abilities}
                        value={form.data.abilities}
                        disabled={form.processing}
                        onChange={(abilities) =>
                            form.setData('abilities', abilities)
                        }
                    />
                    <InputError
                        field="token-abilities"
                        message={abilitiesError}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label id="token-reach-label">{t('Client access')}</Label>
                    <RadioGroup
                        orientation="vertical"
                        id="token-reach"
                        aria-labelledby="token-reach-label"
                        {...fieldErrorProps(
                            'token-reach',
                            form.data.reach === '' ? reachError : null,
                        )}
                        value={form.data.reach}
                        disabled={form.processing}
                        onValueChange={(value) => {
                            setReachError(null);
                            form.setData('reach', value as Draft['reach']);
                        }}
                    >
                        <RadioGroupItem
                            value="client"
                            disabled={page.clients.length === 0}
                        >
                            {t('One client')}
                        </RadioGroupItem>
                        <RadioGroupItem value="all">
                            {t(
                                'All clients you can access, including future clients',
                            )}
                        </RadioGroupItem>
                    </RadioGroup>
                    <InputError
                        field="token-reach"
                        message={form.data.reach === '' ? reachError : null}
                    />
                    {form.data.reach === 'client' && (
                        <>
                            <Label htmlFor="token-client">{t('Client')}</Label>
                            <EntityCombobox
                                id="token-client"
                                options={clientComboboxOptions(page.clients)}
                                value={form.data.client_id}
                                disabled={form.processing}
                                invalid={
                                    !!(form.errors.client_id ?? reachError)
                                }
                                describedBy="token-client-error"
                                onValueChange={(selected) => {
                                    setReachError(null);
                                    form.setData('client_id', selected);
                                }}
                            />
                            <InputError
                                id="token-client-error"
                                message={form.errors.client_id ?? reachError}
                            />
                        </>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label id="token-expiration-label">{t('Expiration')}</Label>
                    <RadioGroup
                        aria-labelledby="token-expiration-label"
                        value={form.data.expiry}
                        disabled={form.processing}
                        onValueChange={(value) =>
                            form.setData('expiry', value as Draft['expiry'])
                        }
                    >
                        <RadioGroupItem value="30-days">
                            {t('30 days')}
                        </RadioGroupItem>
                        <RadioGroupItem value="custom">
                            {t('Custom date')}
                        </RadioGroupItem>
                        <RadioGroupItem value="none">
                            {t('No expiry')}
                        </RadioGroupItem>
                    </RadioGroup>
                    {form.data.expiry === 'custom' && (
                        <>
                            <Label htmlFor="token-expiry">
                                {t('Expires at')}
                            </Label>
                            <DateTimeField
                                id="token-expiry"
                                timezone={page.timezone}
                                value={form.data.expires_at}
                                disabled={form.processing}
                                onValueChange={(value) =>
                                    form.setData('expires_at', value)
                                }
                                invalid={
                                    !!form.errors.expires_at || expiryInvalid
                                }
                                describedBy="token-expiry-error"
                                onInvalidChange={setExpiryInvalid}
                            />
                            <InputError
                                id="token-expiry-error"
                                message={
                                    expiryInvalid
                                        ? 'Enter the date and time in 24-hour format.'
                                        : form.errors.expires_at
                                }
                            />
                        </>
                    )}
                    {form.data.expiry === 'none' && (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'For unattended jobs only. Revoke the token when the job ends.',
                            )}
                        </p>
                    )}
                </div>
            </SheetForm>
        </Sheet>
    );
}

function SecretDialog({
    secret,
    timezone,
    onClose,
}: {
    secret: Disclosure;
    timezone: string;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [copied, copy] = useClipboard();
    const [failed, setFailed] = useState(false);
    const copyNoteId = useId();
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className={dialogClass}>
                <DialogHeader>
                    <DialogTitle>{t('Copy your token now')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'This is the only time you will see it. Use it on servers only, never in a web page. It acts with your rights and stops working if you lose access.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <ShownOnceSecret label={t('Token')} value={secret.value} />
                <div className="space-y-1.5">
                    <Button
                        aria-describedby={failed ? copyNoteId : undefined}
                        onClick={async () =>
                            setFailed(!(await copy(secret.value)))
                        }
                    >
                        <Copy className="size-4" />
                        {t(copied ? 'Copied' : 'Copy')}
                    </Button>
                    <ActionNoteSlot id={copyNoteId}>
                        {failed && (
                            <InputError message="Copy failed. Select the token and copy it manually." />
                        )}
                    </ActionNoteSlot>
                </div>
                <EvidenceBlock
                    wrap
                    title={t('Token details')}
                    rows={[
                        { label: t('Name'), value: secret.name },
                        {
                            label: t('Can'),
                            value: secret.abilities
                                .map((ability) => t(abilityLabel(ability)))
                                .join(', '),
                        },
                        {
                            label: t('Client access'),
                            value:
                                secret.client ??
                                t(
                                    'All clients you can access, including future clients',
                                ),
                        },
                        {
                            label: t('Expires'),
                            value:
                                secret.expires_at === null
                                    ? t('No expiry')
                                    : format.timestamp(
                                          secret.expires_at,
                                          timezone,
                                      ),
                            exact:
                                secret.expires_at === null
                                    ? undefined
                                    : format.exact(secret.expires_at, timezone),
                        },
                    ]}
                />
                <DialogFooter>
                    <Button onClick={onClose}>{t('I have stored it')}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const tokenColumns =
    'min-[87.5rem]:grid-cols-[minmax(8rem,0.9fr)_minmax(0,2fr)_8.5rem_6.5rem_6.5rem_4.5rem] min-[87.5rem]:gap-x-3';

export default function ApiTokens({ tokensPage }: { tokensPage: TokenPage }) {
    const { t } = useTranslation();
    const format = useFormat();
    const [page, setPage] = useState(tokensPage);
    const [creating, setCreating] = useState(false);
    const [secret, setSecret] = useState<Disclosure | null>(null);
    const [revoking, setRevoking] = useState<Token | null>(null);
    const [revokeError, setRevokeError] = useState<string | null>(null);
    const revokeNoteId = useId();
    const [failureMessage, setFailureMessage] = useState<string | null>(null);
    const [revoked, setRevoked] = useState(false);
    const query = useHttpRequest<Record<string, never>, TokenPage>();
    const revoke = useHttpRequest<Record<string, never>, null>();
    useEffect(() => {
        const clear = () => {
            setSecret(null);
            setCreating(false);
        };
        const remove = router.on('before', ({ detail }) => {
            setSecret(null);
            // Shell-health reloads keep the current page and its unsaved draft.
            if (!detail.visit.async) setCreating(false);
        });
        window.addEventListener('pagehide', clear);
        return () => {
            remove();
            window.removeEventListener('pagehide', clear);
        };
    }, []);
    useEffect(() => setPage(tokensPage), [tokensPage]);
    useEffect(() => {
        // History restores the original safe props, not mutations made through the JSON API.
        let active = true;
        void query
            .get(readData.url())
            .then((canonical) => {
                if (active) setPage(canonical);
            })
            .catch((error: unknown) => {
                if (active)
                    setFailureMessage(
                        httpErrorMessage(
                            error,
                            t,
                            'Token metadata request failed. Reload to check the current state.',
                        ),
                    );
            });
        return () => {
            active = false;
            query.cancel();
        };
    }, [query.get, query.cancel]);
    const date = (value: string | null, empty: string, label: string) =>
        value === null ? (
            <span className="whitespace-nowrap text-muted-foreground">
                {t(empty)}
            </span>
        ) : (
            <AbsoluteTime
                value={value}
                timezone={page.timezone}
                mode="calendarDate"
                label={t(label)}
                className="whitespace-nowrap"
            />
        );
    const refresh = async () => {
        try {
            setPage(await query.get(readData.url()));
            setFailureMessage(null);
        } catch (error: unknown) {
            setFailureMessage(
                httpErrorMessage(
                    error,
                    t,
                    'Token metadata request failed. Reload to check the current state.',
                ),
            );
        }
    };
    const closeCreate = () => {
        setCreating(false);
        void refresh();
    };
    const revokeToken = async () => {
        if (!revoking) throw new Error('No token selected for revocation.');
        setRevokeError(null);
        try {
            await revoke.delete(destroy.url(revoking.id));
        } catch (error: unknown) {
            setRevokeError(
                httpErrorMessage(
                    error,
                    t,
                    'Token metadata request failed. Reload to check the current state.',
                ),
            );
            return false;
        }
        setPage((current) => ({
            ...current,
            tokens: current.tokens.filter((token) => token.id !== revoking.id),
        }));
        setRevoked(true);
        setRevokeError(null);
        return true;
    };
    return (
        <>
            <Head title={t('API tokens')} />
            <PageHeader
                title={t('API tokens')}
                description={t('pages.api_tokens.description')}
                actions={
                    <>
                        <Button
                            data-shortcut-create
                            onClick={() => {
                                setCreating(true);
                                setRevoked(false);
                            }}
                        >
                            <Plus className="size-4" />
                            {t('Create token')}
                        </Button>
                    </>
                }
            />
            <p className="pb-3 text-xs text-muted-foreground">
                {t('Your tokens only. Dates use :timezone.', {
                    timezone: page.timezone,
                })}
            </p>
            <InputError className="mb-3" message={failureMessage} />
            {revoked && (
                <p role="status" className="mb-3 text-sm">
                    {t('Token revoked.')}
                </p>
            )}
            <div className="@container/table overflow-hidden rounded-lg border">
                {page.tokens.length > 0 && (
                    <ListHeader
                        className={`${tokenColumns} min-[87.5rem]:grid`}
                    >
                        {[
                            'Name',
                            'Can',
                            'Client access',
                            'Last used',
                            'Expires',
                        ].map((label) => (
                            <span key={label} className="truncate">
                                {t(label)}
                            </span>
                        ))}
                        <span />
                    </ListHeader>
                )}
                {page.tokens.length === 0 && (
                    <EmptyState
                        bare
                        icon={KeyRound}
                        title={t('No API tokens yet.')}
                        description={t(
                            'Create a token for scripts or API clients.',
                        )}
                    />
                )}
                {page.tokens.map((token) => {
                    const client = page.clients.find(
                        (item) => item.id === token.client_id,
                    );
                    const cells = [
                        {
                            label: 'Can',
                            value: (
                                <TokenGrant
                                    abilities={token.abilities}
                                    grantable={page.abilities}
                                />
                            ),
                        },
                        {
                            label: 'Client access',
                            value: (
                                <Badge className="max-w-full gap-1.5 py-0.5 pl-1 font-normal">
                                    {token.client_id === null ? (
                                        <TooltipHint
                                            content={t(
                                                'All clients you can access, including future clients',
                                            )}
                                        >
                                            <span>{t('All clients')}</span>
                                        </TooltipHint>
                                    ) : (
                                        <>
                                            {client && (
                                                <ClientMark
                                                    client={client}
                                                    small
                                                />
                                            )}
                                            <TruncatedText
                                                content={
                                                    client?.name ??
                                                    t(
                                                        'Client unavailable — no access',
                                                    )
                                                }
                                            >
                                                <span className="truncate">
                                                    {client?.name ??
                                                        t(
                                                            'Client unavailable — no access',
                                                        )}
                                                </span>
                                            </TruncatedText>
                                        </>
                                    )}
                                </Badge>
                            ),
                        },
                        {
                            label: 'Last used',
                            value: date(
                                token.last_used_at,
                                'Never used',
                                'Last used',
                            ),
                        },
                        {
                            label: 'Expires',
                            value: (
                                <span className="inline-flex flex-col items-end min-[87.5rem]:items-start">
                                    {date(
                                        token.expires_at,
                                        'No expiry',
                                        'Expires',
                                    )}
                                    {token.expires_at !== null &&
                                        new Date(token.expires_at).getTime() <=
                                            Date.now() && (
                                            <span className="text-xs text-status-warn">
                                                {t('Expired')}
                                            </span>
                                        )}
                                </span>
                            ),
                        },
                    ];
                    return (
                        <ListRow
                            as="article"
                            key={token.id}
                            className={`${tokenColumns} gap-y-2 min-[87.5rem]:items-center`}
                        >
                            {/* Created sits in the name's tooltip so the row stays on one line. */}
                            <TooltipHint
                                content={`${token.name} · ${t('Created')} ${token.created_at === null ? t('Unknown') : format.exact(token.created_at, page.timezone)}`}
                            >
                                <SectionHeading className="truncate">
                                    {token.name}
                                </SectionHeading>
                            </TooltipHint>
                            {cells.map(({ label, value }) => (
                                <div
                                    key={label}
                                    className="flex min-w-0 items-center justify-between gap-3 text-cell min-[87.5rem]:block"
                                >
                                    <span className="shrink-0 text-xs text-muted-foreground min-[87.5rem]:hidden">
                                        {t(label)}
                                    </span>
                                    <div className="flex min-w-0 justify-end min-[87.5rem]:justify-start">
                                        {value}
                                    </div>
                                </div>
                            ))}
                            <Button
                                data-test="revoke-token"
                                variant="ghost"
                                className="justify-self-end"
                                onClick={() => {
                                    setRevoking(token);
                                    setRevokeError(null);
                                }}
                            >
                                {t('Revoke')}
                            </Button>
                        </ListRow>
                    );
                })}
            </div>
            {page.tokens.length > 0 && (
                <div className="mt-3">
                    <ListFooter
                        count={t('api_tokens.list.count', {
                            count: page.tokens.length,
                            formattedCount: format.number(page.tokens.length),
                        })}
                    />
                </div>
            )}
            {creating && (
                <CreateToken
                    page={page}
                    onClose={closeCreate}
                    onCreated={(result) => {
                        setSecret(result);
                        setCreating(false);
                        void refresh();
                    }}
                />
            )}
            {secret && (
                <SecretDialog
                    secret={secret}
                    timezone={page.timezone}
                    onClose={() => setSecret(null)}
                />
            )}
            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRevoking(null);
                        setRevokeError(null);
                    }
                }}
                destructive
                title={t('Revoke token?')}
                description={t(
                    'Requests using :name will stop working immediately. This cannot be undone.',
                    { name: revoking?.name },
                )}
                confirmLabel={t('Revoke token')}
                confirmTestId="confirm-revoke-token"
                confirmDescribedBy={revokeError ? revokeNoteId : undefined}
                onConfirm={revokeToken}
            >
                <ActionNoteSlot id={revokeNoteId}>
                    {revokeError && <InputError message={revokeError} />}
                </ActionNoteSlot>
            </ConfirmDialog>
        </>
    );
}
