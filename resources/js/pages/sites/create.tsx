import { PageHeader } from '@/components/page-header';
import {
    FormSection,
    FormActions,
    FormSecondaryAction,
} from '@/components/form-section';
import {
    SiteClientField,
    SiteNameField,
    SiteTagsField,
    SiteUrlField,
} from '@/components/site-fields';
import { PageContainer } from '@/components/page-container';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { index as sitesIndex, store as sitesStore } from '@/routes/sites';
import type { CheckKey, FieldValue } from '@/types/monitoring';
import { eligibleSiteCheck, siteCreateDraft } from '@/lib/site-create-draft';
import { checkNeedsSetup, SiteChecksList } from '@/components/site-checks-list';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { TextLink } from '@/components/text-link';
import { team as teamNotifications } from '@/routes/settings/notifications';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { useSiteUrlProbe } from '@/hooks/use-site-url-probe';

/** Request error keys in page order with the control a refused Add site focuses. */
const fieldOrder = [
    ['url', 'site-url'],
    ['client_id', 'site-client'],
    ['friendly_name', 'site-name'],
    ['tags', 'site-tags'],
    ['checks', 'site-checks'],
] as const;

/** How far the page has revealed: 0 Address, 1 + Client, 2 + Details and Checks. A revealed section never collapses. */
type Revealed = 0 | 1 | 2;

export default function AddSite({
    clients: suppliedClients,
    checks,
    selected_client_id,
    can_create_site,
    timezones,
}: Heptapod.Data.Ui.AddSitePageData) {
    const { t } = useTranslation();
    const [createdClients, setCreatedClients] = useState<
        Heptapod.Data.Ui.ClientData[]
    >([]);
    const clients = useMemo(
        () => [
            ...suppliedClients,
            ...createdClients.filter(
                (client) =>
                    !suppliedClients.some(
                        (existing) => existing.id === client.id,
                    ),
            ),
        ],
        [suppliedClients, createdClients],
    );
    const [newClientName, setNewClientName] = useState<string | null>(null);
    const { auth, overallHealth } = usePage().props;
    if (!auth.user)
        throw new Error('Site creation requires an authenticated user.');
    const draftKey = `heptapod:site-create:${auth.user.id}`;
    const options = {
        clients,
        checks,
        selected_client_id,
        can_create_site,
        timezones,
    };
    const [initial] = useState(() => {
        if (typeof window === 'undefined')
            return { draft: null, unavailable: false };
        try {
            const stored = localStorage.getItem(draftKey);
            return {
                draft: stored
                    ? siteCreateDraft(JSON.parse(stored), options)
                    : null,
                unavailable: false,
            };
        } catch (error) {
            if (error instanceof SyntaxError)
                return { draft: null, unavailable: false };
            return { draft: null, unavailable: true };
        }
    });
    const [draftUnavailable, setDraftUnavailable] = useState(
        initial.unavailable,
    );
    const draftCleared = useRef(false);
    const [openChecks, setOpenChecks] = useState<CheckKey[]>(
        initial.draft?.openChecks ?? [],
    );
    const changedChecks = useRef(
        new Set<CheckKey>(initial.draft?.changedChecks),
    );
    const form = useForm({
        url: initial.draft?.url ?? '',
        // Preselect only without a real choice: in a multi-client team the first client is a wrong-client trap.
        client_id:
            initial.draft?.client_id ||
            String(
                selected_client_id ??
                    (clients.length === 1 ? clients[0]!.id : ''),
            ),
        friendly_name: initial.draft?.friendly_name ?? '',
        tags: initial.draft?.tags ?? '',
        checks:
            initial.draft?.checks ??
            checks
                .filter(
                    (check) => check.recommended && !check.unavailable_reason,
                )
                .map((check) => check.key),
        settings: initial.draft?.settings ?? ({} as Record<string, FieldValue>),
    });
    useEffect(() => {
        if (draftCleared.current || draftUnavailable) return;
        try {
            localStorage.setItem(
                draftKey,
                JSON.stringify(
                    siteCreateDraft(
                        {
                            ...form.data,
                            openChecks,
                            changedChecks: [...changedChecks.current],
                            omitted: initial.draft?.omitted,
                        },
                        options,
                    ),
                ),
            );
        } catch {
            setDraftUnavailable(true);
        }
    }, [form.data, openChecks, draftKey, draftUnavailable, clients, checks]);
    const clearDraft = () => {
        draftCleared.current = true;
        try {
            localStorage.removeItem(draftKey);
        } catch {
            setDraftUnavailable(true);
        }
    };
    const {
        probe,
        probeState,
        probeError,
        addressInvalid,
        runProbe,
        clearProbe,
    } = useSiteUrlProbe(form.data.url, true);
    useEffect(() => {
        if (!probe) return;
        form.setData((data) => ({
            ...data,
            checks: checks
                .filter(
                    (check) =>
                        eligibleSiteCheck(check, data.url) &&
                        (changedChecks.current.has(check.key)
                            ? data.checks.includes(check.key)
                            : probe.recommended_checks.includes(check.key)),
                )
                .map((check) => check.key),
        }));
    }, [probe]);
    useEffect(() => {
        if (initial.draft?.url) void runProbe();
    }, []);
    const isEligible = (check: (typeof checks)[number]) =>
        eligibleSiteCheck(check, form.data.url);
    const selectedChecks = checks.filter(
        (check) =>
            isEligible(check) &&
            form.data.checks.includes(check.key) &&
            (check.key !== 'performance' ||
                form.data.checks.includes('uptime')),
    );
    const address = probe?.normalised_url ?? form.data.url;
    const host = URL.canParse(address) ? new URL(address).hostname : null;
    const client = clients.find(
        (candidate) => String(candidate.id) === form.data.client_id,
    );

    // The probe has answered for an address the server accepts; an unreachable one only warns.
    const answered =
        probeState === 'done' || (probeState === 'error' && !addressInvalid);
    const [revealed, setRevealed] = useState<Revealed>(() =>
        initial.draft?.url ? (form.data.client_id ? 2 : 1) : 0,
    );
    const [restored] = useState(revealed);
    const reachable: Revealed =
        answered || revealed > 0 ? (form.data.client_id ? 2 : 1) : 0;
    if (reachable > revealed) setRevealed(reachable);
    // Set by Enter/Tab, cleared by any other key or a pointer: a section revealed after a keyboard finish takes focus.
    const finishedByKeyboard = useRef(false);
    useEffect(() => {
        if (!finishedByKeyboard.current || revealed === restored) return;
        finishedByKeyboard.current = false;
        // After the combobox popover has returned focus to its trigger.
        requestAnimationFrame(() =>
            document
                .getElementById(revealed === 2 ? 'site-name' : 'site-client')
                ?.focus(),
        );
    }, [revealed]);

    const focusFirstError = (errors: Record<string, string>) => {
        const keys = Object.keys(errors);
        const id = fieldOrder.find(([field]) =>
            keys.some(
                (key) =>
                    key === field ||
                    (field === 'tags' && key.startsWith('tags.')),
            ),
        )?.[1];
        if (!id) return;
        // After the render that reveals every section; the checks group itself is not focusable, its first switch is.
        requestAnimationFrame(() => {
            const target =
                id === 'site-checks'
                    ? document.querySelector<HTMLElement>(
                          '#site-checks [role="switch"]:not(:disabled)',
                      )
                    : document.getElementById(id);
            target?.scrollIntoView({ block: 'center' });
            target?.focus({ preventScroll: true });
        });
    };
    const submit = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Focus goes to the first error, not to a section this submit reveals.
        finishedByKeyboard.current = false;
        setRevealed(2);
        const eligibleChecks = selectedChecks.map((check) => check.key);
        const errors: Record<string, string> = {};
        if (!form.data.url.trim())
            errors.url = t(
                'Enter a valid HTTP or HTTPS URL without credentials.',
            );
        if (!form.data.client_id) errors.client_id = t('Select a client.');
        if (!eligibleChecks.length)
            errors.checks = t('Select at least one check.');
        form.clearErrors();
        if (Object.keys(errors).length) {
            form.setError(errors as never);
            focusFirstError(errors);
            return;
        }
        const settings = Object.fromEntries(
            checks
                .filter((check) => eligibleChecks.includes(check.key))
                .map(
                    (check) =>
                        [
                            check.key + '_check_settings',
                            Object.fromEntries(
                                Object.entries(form.data.settings)
                                    .filter(([key]) =>
                                        key.startsWith(check.key + '.'),
                                    )
                                    .map(([key, value]) => [
                                        key.slice(check.key.length + 1),
                                        value,
                                    ]),
                            ),
                        ] as const,
                )
                .filter(([, value]) => Object.keys(value).length),
        );
        const tags = [
            ...new Set(
                form.data.tags
                    .split(',')
                    .map((tag) => tag.trim())
                    .filter(Boolean),
            ),
        ];
        form.transform(({ url, client_id, friendly_name }) => ({
            url,
            client_id,
            ...(friendly_name.trim()
                ? { friendly_name: friendly_name.trim() }
                : {}),
            ...(tags.length ? { tags } : {}),
            checks: eligibleChecks,
            ...settings,
        }));
        form.post(sitesStore.url(), {
            onSuccess: clearDraft,
            onError: (errors) => {
                focusFirstError(errors);
                setOpenChecks((current) => [
                    ...new Set([
                        ...current,
                        ...checks
                            .filter((check) =>
                                Object.keys(errors).some((key) =>
                                    key.startsWith('checks.' + check.key + '.'),
                                ),
                            )
                            .map((check) => check.key),
                    ]),
                ]);
            },
        });
    };
    const errorCount = Object.keys(form.errors).length;
    const reasons: Partial<Record<CheckKey, string>> = probe
        ? {
              ...(probe.status && {
                  uptime: t('add_site.reason.status', {
                      status: probe.status,
                  }),
              }),
              ...(probe.certificate_issuer && {
                  certificate_health: t('add_site.reason.certificate', {
                      issuer: probe.certificate_issuer,
                  }),
              }),
              ...(probe.sitemap_url && {
                  sitemap: t('add_site.reason.sitemap'),
              }),
              ...(probe.public_html && {
                  broken_links: t('add_site.reason.public_html'),
                  mixed_content: t('add_site.reason.public_html'),
                  lighthouse: t('add_site.reason.public_html'),
              }),
          }
        : {};
    const revealClass = (level: Revealed) =>
        level > restored ? 'motion-row-in' : undefined;
    return (
        <>
            <Head title={t('Add site')} />
            <PageContainer>
                <PageHeader
                    title={t('Add site')}
                    description={t('pages.add_site.description')}
                />
                <form
                    noValidate
                    id="site-create-form"
                    onSubmit={submit}
                    onKeyDownCapture={(event) => {
                        finishedByKeyboard.current =
                            event.key === 'Enter' || event.key === 'Tab';
                    }}
                    onPointerDownCapture={() => {
                        finishedByKeyboard.current = false;
                    }}
                    className="space-y-6"
                >
                    {initial.draft?.omitted && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('sites.draft.secrets_omitted')}
                        </p>
                    )}
                    {draftUnavailable && (
                        <p
                            role="alert"
                            className="text-sm text-muted-foreground"
                        >
                            {t('sites.draft.unavailable')}
                        </p>
                    )}
                    <FormSection
                        title={t('Address')}
                        description={t('Add the address you want to monitor.')}
                    >
                        <SiteUrlField
                            id="site-url"
                            name="url"
                            type="text"
                            inputMode="url"
                            required
                            autoFocus
                            placeholder="https://shop.example"
                            value={form.data.url}
                            onChange={(event) => {
                                form.setData('url', event.target.value);
                                form.clearErrors('url');
                                clearProbe();
                            }}
                            onBlur={() =>
                                void runProbe().then((result) => {
                                    if (result?.normalised_url)
                                        form.setData(
                                            'url',
                                            result.normalised_url,
                                        );
                                })
                            }
                            onKeyDown={(event) => {
                                // Enter finishes the address like Tab while Client is still ahead; then it submits the form.
                                if (event.key !== 'Enter' || revealed > 0)
                                    return;
                                event.preventDefault();
                                void runProbe().then((result) => {
                                    if (result?.normalised_url)
                                        form.setData(
                                            'url',
                                            result.normalised_url,
                                        );
                                });
                            }}
                            probe={probe}
                            probeState={probeState}
                            probeError={probeError}
                            onUseAddress={(url) => {
                                clearProbe();
                                form.setData('url', url);
                            }}
                            onRetry={() => void runProbe(true)}
                            facts
                            error={form.errors.url}
                            help={t(
                                'We check the address before adding the site',
                            )}
                        />
                    </FormSection>
                    {revealed > 0 && (
                        <div className={revealClass(1)}>
                            <FormSection
                                title={t('Client')}
                                description={t(
                                    'Choose a client for this site.',
                                )}
                            >
                                <SiteClientField
                                    clients={clients}
                                    onCreateClient={
                                        can_create_site
                                            ? setNewClientName
                                            : undefined
                                    }
                                    value={form.data.client_id}
                                    onValueChange={(value) => {
                                        form.setData('client_id', value);
                                        form.clearErrors('client_id');
                                    }}
                                    required
                                    error={form.errors.client_id}
                                />
                            </FormSection>
                        </div>
                    )}
                    {revealed > 1 && (
                        <div className={revealClass(2)}>
                            <FormSection
                                title={t('Details')}
                                description={t(
                                    'How this site appears in your portfolio.',
                                )}
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SiteNameField
                                        value={form.data.friendly_name}
                                        placeholder={host ?? undefined}
                                        onChange={(event) => {
                                            form.setData(
                                                'friendly_name',
                                                event.target.value,
                                            );
                                            form.clearErrors('friendly_name');
                                        }}
                                        error={form.errors.friendly_name}
                                    />
                                    <SiteTagsField
                                        value={form.data.tags}
                                        onChange={(event) => {
                                            form.setData(
                                                'tags',
                                                event.target.value,
                                            );
                                            form.clearErrors('tags');
                                        }}
                                        error={form.errors.tags}
                                    />
                                </div>
                            </FormSection>
                            <FormSection
                                title={t('Checks')}
                                description={t(
                                    'Recommended checks are selected.',
                                )}
                                detail={t(
                                    'Open Settings to change their defaults.',
                                )}
                            >
                                <InputError
                                    field="site-checks"
                                    message={form.errors.checks}
                                />
                                {overallHealth &&
                                    !overallHealth.notifications_covered && (
                                        <Alert
                                            tone="warning"
                                            action={
                                                auth.canManageChannels ? (
                                                    <TextLink
                                                        href={teamNotifications.url()}
                                                    >
                                                        {t(
                                                            'add_site.no_rules.action',
                                                        )}
                                                    </TextLink>
                                                ) : undefined
                                            }
                                        >
                                            <AlertTitle>
                                                {t('add_site.no_rules.title')}
                                            </AlertTitle>
                                            <AlertDescription>
                                                {t('add_site.no_rules.body')}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                <SiteChecksList
                                    groupProps={{
                                        'aria-label': t('Checks'),
                                        ...fieldErrorProps(
                                            'site-checks',
                                            form.errors.checks,
                                        ),
                                    }}
                                    checks={checks}
                                    url={form.data.url}
                                    recommended={
                                        probe?.recommended_checks ??
                                        checks
                                            .filter(
                                                (check) => check.recommended,
                                            )
                                            .map((check) => check.key)
                                    }
                                    reasons={reasons}
                                    selected={form.data.checks}
                                    settings={form.data.settings}
                                    canManage={can_create_site}
                                    onSettings={(key) => setOpenChecks([key])}
                                    onToggle={(key, enabled) => {
                                        changedChecks.current.add(key);
                                        if (key === 'uptime' && !enabled)
                                            changedChecks.current.add(
                                                'performance',
                                            );
                                        form.clearErrors('checks');
                                        form.setData(
                                            'checks',
                                            enabled
                                                ? [...form.data.checks, key]
                                                : form.data.checks.filter(
                                                      (selected) =>
                                                          selected !== key &&
                                                          !(
                                                              key ===
                                                                  'uptime' &&
                                                              selected ===
                                                                  'performance'
                                                          ),
                                                  ),
                                        );
                                        const check = checks.find(
                                            (option) => option.key === key,
                                        )!;
                                        // As in Site settings, a check is never added without its required settings.
                                        if (
                                            enabled &&
                                            checkNeedsSetup(
                                                check,
                                                form.data.settings,
                                            )
                                        )
                                            setOpenChecks([key]);
                                    }}
                                />
                            </FormSection>
                        </div>
                    )}
                    <FormActions
                        status={
                            form.processing
                                ? t('Saving…')
                                : errorCount
                                  ? t('add_site.summary.attention', {
                                        count: errorCount,
                                    })
                                  : host
                                    ? [
                                          host,
                                          client?.name,
                                          t('add_site.count.footer', {
                                              count: selectedChecks.length,
                                          }),
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : t('add_site.summary.empty')
                        }
                    >
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={() => {
                                clearDraft();
                                router.visit(sitesIndex.url());
                            }}
                        >
                            {t('Discard')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            form="site-create-form"
                            disabled={form.processing}
                        >
                            {t('Add site')}
                        </Button>
                    </FormActions>
                </form>
            </PageContainer>
            {checks
                .filter(
                    (check) =>
                        revealed === 2 &&
                        openChecks[0] === check.key &&
                        isEligible(check),
                )
                .map((check) => (
                    <CheckSettingsSheet
                        key={check.key}
                        check={check}
                        settings={form.data.settings}
                        errors={Object.fromEntries(
                            Object.entries(form.errors)
                                .filter(([key]) =>
                                    key.startsWith(`checks.${check.key}.`),
                                )
                                .map(([key, message]) => [
                                    key.slice('checks.'.length),
                                    message,
                                ]),
                        )}
                        onClose={() => {
                            // Cancelled without its required settings, the check stays off.
                            if (checkNeedsSetup(check, form.data.settings))
                                form.setData(
                                    'checks',
                                    form.data.checks.filter(
                                        (key) => key !== check.key,
                                    ),
                                );
                            setOpenChecks([]);
                        }}
                        onApply={(settings) => {
                            form.setData('settings', settings);
                            form.clearErrors();
                            setOpenChecks([]);
                        }}
                    />
                ))}
            {newClientName !== null && (
                <ClientCreateSheet
                    initialName={newClientName}
                    timezones={timezones}
                    onClose={() => setNewClientName(null)}
                    onCreated={(created) => {
                        setCreatedClients((current) => [...current, created]);
                        form.setData('client_id', String(created.id));
                        setNewClientName(null);
                    }}
                />
            )}
        </>
    );
}
