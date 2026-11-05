import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ClientMark } from '@/components/client-mark';
import { emptySelectValue } from '@/components/ui/select';
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
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    EvidenceBlock,
    ShownOnceSecret,
} from '@/components/foundation-primitives';
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
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { zonedInstant } from '@/lib/date-time';
import { TokenAbilities, abilityLabel } from '@/components/token-abilities';
import { data as readData, destroy, store } from '@/routes/settings/api-tokens';

type TokenPage = Heptapod.Data.Ui.ApiTokenSettingsData;
type Token = TokenPage['tokens'][number];
type Draft = {
    name: string;
    preset: keyof TokenPage['presets'];
    client_id: string;
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
    // Unmount the form after issuance: the request also holds its response in component memory.
    const form = useHttpRequest<Draft, { token: string }>({
        name: '',
        preset: 'read',
        client_id: '',
        expires_at: '',
    });
    // The expiry is entered as a wall time in the account timezone, like every other date on this page.
    const expiresAt = form.data.expires_at
        ? zonedInstant(form.data.expires_at, page.timezone)
        : null;
    const [failure, setFailure] = useState(false);
    const [expiryInvalid, setExpiryInvalid] = useState(false);
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
    }, [form.processing, form.errors]);
    const submit = async (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Unparsable typed expiry fails loud; never send the stale value.
        if (expiryInvalid) return;
        if (form.data.expires_at && expiresAt === null) return;
        form.transform((draft) => ({
            ...draft,
            client_id: draft.client_id ? Number(draft.client_id) : null,
            expires_at: expiresAt,
        }));
        try {
            const result = await form.post(store.url(), {
                onError: (errors) => {
                    const ids: Record<string, string> = {
                        name: 'token-name',
                        preset: 'token-preset',
                        client_id: 'token-client',
                        expires_at: 'token-expiry',
                    };
                    const first = Object.keys(errors)[0];
                    focus.current = first ? ids[first] : undefined;
                },
            });
            if (mounted.current)
                onCreated({
                    value: result.token,
                    name: form.data.name,
                    abilities: page.presets[form.data.preset],
                    client: form.data.client_id
                        ? page.clients.find(
                              (client) =>
                                  client.id === Number(form.data.client_id),
                          )!.name
                        : null,
                    expires_at: expiresAt,
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
                    <Label id="token-preset-label">{t('Preset')}</Label>
                    <RadioGroup
                        orientation="vertical"
                        id="token-preset"
                        aria-labelledby="token-preset-label"
                        {...fieldErrorProps('token-preset', form.errors.preset)}
                        value={String(form.data.preset)}
                        disabled={form.processing}
                        onValueChange={(value) =>
                            form.setData('preset', value as Draft['preset'])
                        }
                    >
                        <RadioGroupItem value="agent-read">
                            {t('Read for coding agents')}
                        </RadioGroupItem>
                        <RadioGroupItem value={String('read')}>
                            {t('Read')}
                        </RadioGroupItem>
                        <RadioGroupItem value={String('write')}>
                            {t('Write')}
                        </RadioGroupItem>
                        <RadioGroupItem value={String('manage')}>
                            {t('Manage')}
                        </RadioGroupItem>
                    </RadioGroup>
                    <InputError
                        field="token-preset"
                        message={form.errors.preset}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('Effective abilities')}
                    </p>
                    <TokenAbilities
                        abilities={page.presets[form.data.preset]}
                    />
                    {JSON.stringify(page.presets.write) ===
                        JSON.stringify(page.presets.manage) && (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Write and Manage currently grant the same effective abilities for your role.',
                            )}
                        </p>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="token-client">{t('Reach')}</Label>
                    <EntityCombobox
                        id="token-client"
                        options={[
                            {
                                value: emptySelectValue,
                                label: t('All currently accessible clients'),
                            },
                            ...clientComboboxOptions(page.clients),
                        ]}
                        value={String(form.data.client_id) || emptySelectValue}
                        disabled={form.processing}
                        invalid={!!form.errors.client_id}
                        describedBy="token-client-error"
                        onValueChange={(selected) => {
                            const value =
                                selected === emptySelectValue ? '' : selected;
                            form.setData('client_id', value);
                        }}
                    />
                    <InputError
                        id="token-client-error"
                        message={form.errors.client_id}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="token-expiry">
                        {t('Expires at (optional)')}
                    </Label>
                    <DateTimeField
                        id="token-expiry"
                        timezone={page.timezone}
                        value={form.data.expires_at}
                        disabled={form.processing}
                        onValueChange={(value) =>
                            form.setData('expires_at', value)
                        }
                        invalid={!!form.errors.expires_at || expiryInvalid}
                        describedBy="token-expiry-help token-expiry-error"
                        onInvalidChange={setExpiryInvalid}
                    />
                    <p
                        id="token-expiry-help"
                        className="text-xs text-muted-foreground"
                    >
                        {t('Leave empty for no expiry.')}
                    </p>
                    <InputError
                        id="token-expiry-error"
                        message={
                            expiryInvalid
                                ? 'Enter the date and time in 24-hour format.'
                                : form.errors.expires_at
                        }
                    />
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
                <div>
                    <Button
                        size="sm"
                        onClick={async () =>
                            setFailed(!(await copy(secret.value)))
                        }
                    >
                        <Copy className="size-4" />
                        {t(copied ? 'Copied' : 'Copy')}
                    </Button>
                    <InputError
                        className="pt-2"
                        message={
                            failed
                                ? 'Copy failed. Select the token and copy it manually.'
                                : null
                        }
                    />
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
                            label: t('Reach'),
                            value:
                                secret.client ??
                                t('All currently accessible clients'),
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

export default function ApiTokens({ tokensPage }: { tokensPage: TokenPage }) {
    const { t } = useTranslation();
    const format = useFormat();
    const [page, setPage] = useState(tokensPage);
    const [creating, setCreating] = useState(false);
    const [secret, setSecret] = useState<Disclosure | null>(null);
    const [revoking, setRevoking] = useState<Token | null>(null);
    const [failure, setFailure] = useState(false);
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
            .catch(() => {
                if (active) setFailure(true);
            });
        return () => {
            active = false;
            query.cancel();
        };
    }, [query.get, query.cancel]);
    const date = (value: string | null, empty: string) =>
        value === null ? (
            t(empty)
        ) : (
            <AbsoluteTime value={value} timezone={page.timezone} />
        );
    const refresh = async () => {
        try {
            setPage(await query.get(readData.url()));
            setFailure(false);
        } catch {
            setFailure(true);
        }
    };
    const closeCreate = () => {
        setCreating(false);
        void refresh();
    };
    const revokeToken = async () => {
        if (!revoking) throw new Error('No token selected for revocation.');
        try {
            await revoke.delete(destroy.url(revoking.id));
        } catch {
            setFailure(true);
            return false;
        }
        setPage((current) => ({
            ...current,
            tokens: current.tokens.filter((token) => token.id !== revoking.id),
        }));
        setRevoked(true);
        setFailure(false);
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
                            size="sm"
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
            <InputError
                className="mb-3"
                message={
                    failure
                        ? 'Token metadata request failed. Reload to check the current state.'
                        : null
                }
            />
            {revoked && (
                <p role="status" className="mb-3 text-sm">
                    {t('Token revoked.')}
                </p>
            )}
            <div className="@container/table overflow-hidden rounded-lg border">
                {page.tokens.length > 0 && (
                    <ListHeader className="grid-cols-[minmax(120px,1fr)_minmax(130px,1.3fr)_minmax(100px,.8fr)_minmax(90px,.7fr)_minmax(90px,.7fr)_minmax(90px,.7fr)_5.5rem] min-[87.5rem]:grid">
                        {[
                            'Name',
                            'Can',
                            'Reach',
                            'Created',
                            'Last used',
                            'Expires',
                        ].map((label) => (
                            <span key={label}>{t(label)}</span>
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
                {page.tokens.map((token) => (
                    <ListRow
                        as="article"
                        key={token.id}
                        className="min-[87.5rem]:grid-cols-[minmax(120px,1fr)_minmax(130px,1.3fr)_minmax(100px,.8fr)_minmax(90px,.7fr)_minmax(90px,.7fr)_minmax(90px,.7fr)_5.5rem] min-[87.5rem]:items-start"
                    >
                        <SectionHeading className="break-words">
                            {token.name}
                        </SectionHeading>
                        <div className="space-y-1">
                            <span className="text-xs text-muted-foreground min-[87.5rem]:hidden">
                                {t('Can')}
                            </span>
                            <TokenAbilities abilities={token.abilities} />
                        </div>
                        {[
                            {
                                label: 'Reach',
                                value:
                                    token.client_id === null
                                        ? t('All currently accessible clients')
                                        : token.client_name === null
                                          ? t('Client unavailable — no access')
                                          : page.clients
                                                .filter(
                                                    (client) =>
                                                        client.id ===
                                                        token.client_id,
                                                )
                                                .map((client) => (
                                                    <span
                                                        key={client.id}
                                                        className="inline-flex items-center gap-2"
                                                    >
                                                        <ClientMark
                                                            client={client}
                                                            small
                                                        />
                                                        {client.name}
                                                    </span>
                                                )),
                            },
                            {
                                label: 'Created',
                                value: date(token.created_at, 'Unknown'),
                            },
                            {
                                label: 'Last used',
                                value: date(token.last_used_at, 'Never used'),
                            },
                            {
                                label: 'Expires',
                                value: date(token.expires_at, 'No expiry'),
                            },
                        ].map(({ label, value }) => (
                            <div
                                key={label}
                                className="flex justify-between gap-3 text-cell min-[87.5rem]:block"
                            >
                                <span className="text-xs text-muted-foreground min-[87.5rem]:hidden">
                                    {t(label)}
                                </span>
                                <span
                                    className={
                                        (label === 'Created' &&
                                            token.created_at !== null) ||
                                        (label === 'Last used' &&
                                            token.last_used_at !== null) ||
                                        (label === 'Expires' &&
                                            token.expires_at !== null)
                                            ? 'min-w-0 font-mono break-words tabular-nums'
                                            : 'min-w-0 break-words'
                                    }
                                >
                                    {value}
                                    {label === 'Expires' &&
                                        token.expires_at !== null &&
                                        new Date(token.expires_at).getTime() <=
                                            Date.now() && (
                                            <span className="block text-xs text-(--status-warn)">
                                                {t('Expired')}
                                            </span>
                                        )}
                                </span>
                            </div>
                        ))}
                        <Button
                            data-test="revoke-token"
                            size="sm"
                            variant="ghost"
                            className="justify-self-end"
                            onClick={() => {
                                setRevoking(token);
                                setFailure(false);
                            }}
                        >
                            {t('Revoke')}
                        </Button>
                    </ListRow>
                ))}
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
                    if (!open) setRevoking(null);
                }}
                destructive
                title={t('Revoke token?')}
                description={t(
                    'Requests using :name will stop working immediately. This cannot be undone.',
                    { name: revoking?.name },
                )}
                confirmLabel={t('Revoke token')}
                confirmTestId="confirm-revoke-token"
                onConfirm={revokeToken}
            >
                <InputError
                    message={
                        failure
                            ? 'Revocation request failed. Reload to check whether the token still exists.'
                            : null
                    }
                />
            </ConfirmDialog>
        </>
    );
}
