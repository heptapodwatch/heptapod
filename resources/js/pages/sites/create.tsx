import { PageHeader } from '@/components/page-header';
import {
    FormSection,
    FormActions,
    FormSecondaryAction,
} from '@/components/form-section';
import {
    SiteNameField,
    SiteTagsField,
    SiteUrlField,
} from '@/components/site-fields';
import { PageContainer } from '@/components/page-container';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { index as sitesIndex, store as sitesStore } from '@/routes/sites';
import type { CheckKey, FieldValue } from '@/types/monitoring';
import {
    eligibleSiteCheck,
    siteCreateDraft,
    siteCreateDraftLifetime,
} from '@/lib/site-create-draft';
import { useFormat } from '@/hooks/use-format';
import { checkNeedsSetup, SiteChecksList } from '@/components/site-checks-list';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { TextLink } from '@/components/text-link';
import { team as teamNotifications } from '@/routes/settings/notifications';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { ClientField } from '@/components/client-field';
import { usePickerEntities } from '@/components/search-combobox';
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

export default function AddSite(props: Heptapod.Data.Ui.AddSitePageData) {
    // Start over remounts the form for a fresh state.
    const [formKey, setFormKey] = useState(0);
    return (
        <AddSiteForm
            key={formKey}
            {...props}
            onStartOver={() => setFormKey((key) => key + 1)}
        />
    );
}

function AddSiteForm({
    checks,
    selected_client_id,
    can_create_site,
    timezones,
    only_client_id,
    onStartOver,
}: Heptapod.Data.Ui.AddSitePageData & {
    onStartOver: () => void;
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const [newClientName, setNewClientName] = useState<string | null>(null);
    const { auth, overallHealth } = usePage().props;
    if (!auth.user)
        throw new Error('Site creation requires an authenticated user.');
    const draftKey = `heptapod:site-create:${auth.user.id}`;
    const options = {
        checks,
        selected_client_id,
        can_create_site,
        timezones,
        only_client_id,
    };
    const [initial] = useState(() => {
        if (typeof window === 'undefined')
            return { draft: null, unavailable: false };
        try {
            const stored = localStorage.getItem(draftKey);
            if (!stored) return { draft: null, unavailable: false };
            const draft = siteCreateDraft(JSON.parse(stored), options);
            const age = Date.now() - Date.parse(draft.saved_at);
            if (age >= 0 && age <= siteCreateDraftLifetime)
                return { draft, unavailable: false };
            localStorage.removeItem(draftKey);
            return { draft: null, unavailable: false };
        } catch (error) {
            if (error instanceof SyntaxError)
                return { draft: null, unavailable: false };
            return { draft: null, unavailable: true };
        }
    });
    const [draftUnavailable, setDraftUnavailable] = useState(
        initial.unavailable,
    );
    // Set by a user change, cleared by save, Discard and another tab removing the draft: only edits write a draft.
    const edited = useRef(false);
    const [openChecks, setOpenChecks] = useState<CheckKey[]>(
        initial.draft?.openChecks ?? [],
    );
    const changedChecks = useRef(
        new Set<CheckKey>(initial.draft?.changedChecks),
    );
    // Preselect only without a real choice: in a multi-client team the first client is a wrong-client trap.
    const defaultClientId = String(selected_client_id ?? only_client_id ?? '');
    const defaultChecks = checks
        .filter((check) => check.recommended && !check.unavailable_reason)
        .map((check) => check.key);
    const form = useForm({
        url: initial.draft?.url ?? '',
        // The client the page was opened for beats an older draft's client.
        client_id: String(
            selected_client_id ?? (initial.draft?.client_id || defaultClientId),
        ),
        friendly_name: initial.draft?.friendly_name ?? '',
        tags: initial.draft?.tags ?? '',
        checks: initial.draft?.checks ?? defaultChecks,
        settings: initial.draft?.settings ?? ({} as Record<string, FieldValue>),
    });
    useEffect(() => {
        if (!edited.current || draftUnavailable) return;
        // One write per edit: the probe's check refresh or an opened sheet never renews `saved_at`.
        edited.current = false;
        const draft = siteCreateDraft(
            {
                ...form.data,
                openChecks,
                changedChecks: [...changedChecks.current],
                omitted: initial.draft?.omitted,
                saved_at: new Date().toISOString(),
            },
            options,
        );
        const untouched =
            !draft.url &&
            !draft.friendly_name.trim() &&
            !draft.tags.trim() &&
            draft.client_id === defaultClientId &&
            // Unchanged checks follow the recommendation; a changed one is back at its default when it matches the page's own.
            draft.changedChecks.every(
                (key) =>
                    draft.checks.includes(key) === defaultChecks.includes(key),
            ) &&
            Object.entries(draft.settings).every(([key, value]) => {
                const dot = key.indexOf('.');
                const field = checks.find(
                    (check) => check.key === key.slice(0, dot),
                )?.config_schema.fields[key.slice(dot + 1)];
                return JSON.stringify(value) === JSON.stringify(field?.default);
            });
        try {
            if (untouched) localStorage.removeItem(draftKey);
            else localStorage.setItem(draftKey, JSON.stringify(draft));
        } catch {
            setDraftUnavailable(true);
        }
    }, [form.data, openChecks, draftKey, draftUnavailable, checks]);
    useEffect(() => {
        // Another tab saved the site or discarded the draft (clear() reports no key); write again only after a new edit here.
        const onStorage = (event: StorageEvent) => {
            if (
                (event.key === draftKey || event.key === null) &&
                event.newValue === null
            )
                edited.current = false;
        };
        window.addEventListener('storage', onStorage);
        return () => window.removeEventListener('storage', onStorage);
    }, [draftKey]);
    /** False when storage refused; the page then keeps its form and shows `sites.draft.unavailable`. */
    const clearDraft = () => {
        edited.current = false;
        try {
            localStorage.removeItem(draftKey);
            return true;
        } catch {
            setDraftUnavailable(true);
            return false;
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
    /** Checks the address now (blur, Enter, restore) and puts the normalised form into the field. */
    const checkAddress = () =>
        void runProbe().then((result) => {
            if (result?.normalised_url)
                form.setData('url', result.normalised_url);
        });
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
        if (initial.draft?.url) checkAddress();
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
    const chosenClient = usePickerEntities(
        'clients',
        /^[1-9][0-9]*$/.test(form.data.client_id)
            ? [Number(form.data.client_id)]
            : [],
    );
    const [client] = chosenClient.entities;
    // A restored draft's client the user can no longer choose is dropped, as before: the field asks for a client again.
    useEffect(() => {
        if (chosenClient.absent.has(Number(form.data.client_id)))
            form.setData('client_id', '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chosenClient.absent]);

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
    // A focus move waits a frame; this form unmounting (navigation, Start over) cancels it, so it never lands on the next page's field with the same id.
    const focusFrame = useRef(0);
    useEffect(() => () => cancelAnimationFrame(focusFrame.current), []);
    useEffect(() => {
        if (!finishedByKeyboard.current || revealed === restored) return;
        finishedByKeyboard.current = false;
        // After the combobox popover has returned focus to its trigger.
        focusFrame.current = requestAnimationFrame(() =>
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
        focusFrame.current = requestAnimationFrame(() => {
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
    // The server validates each tag as `tags.N`; the Tags field shows and clears them as its own.
    const errors = form.errors as Record<string, string>;
    const fieldErrorKeys = Object.keys(errors).filter(
        (key) => key !== '_refusal',
    );
    const tagErrorKeys = fieldErrorKeys.filter(
        (key) => key === 'tags' || key.startsWith('tags.'),
    );
    const errorCount = new Set(
        fieldErrorKeys.map((key) =>
            tagErrorKeys.includes(key) ? 'tags' : key,
        ),
    ).size;
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
                    notices={
                        initial.draft ? (
                            <Alert
                                tone="info"
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={() => {
                                            if (clearDraft()) onStartOver();
                                        }}
                                    >
                                        {t('sites.draft.start_over')}
                                    </Button>
                                }
                            >
                                <AlertTitle>
                                    {t('sites.draft.restored', {
                                        time: format.time(
                                            initial.draft.saved_at,
                                        ),
                                    })}
                                </AlertTitle>
                            </Alert>
                        ) : undefined
                    }
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
                                edited.current = true;
                                form.setData('url', event.target.value);
                                form.clearErrors('url');
                                clearProbe();
                            }}
                            onBlur={checkAddress}
                            onKeyDown={(event) => {
                                // Enter finishes the address like Tab while Client is still ahead; then it submits the form.
                                if (event.key !== 'Enter' || revealed > 0)
                                    return;
                                event.preventDefault();
                                checkAddress();
                            }}
                            probe={probe}
                            probeState={probeState}
                            probeError={probeError}
                            onUseAddress={(url) => {
                                edited.current = true;
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
                                <ClientField
                                    onCreateClient={
                                        can_create_site
                                            ? setNewClientName
                                            : undefined
                                    }
                                    value={form.data.client_id}
                                    onValueChange={(value) => {
                                        edited.current = true;
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
                                            edited.current = true;
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
                                            edited.current = true;
                                            form.setData(
                                                'tags',
                                                event.target.value,
                                            );
                                            // clearErrors() without keys would clear every field.
                                            if (tagErrorKeys.length)
                                                form.clearErrors(
                                                    ...(tagErrorKeys as 'tags'[]),
                                                );
                                        }}
                                        error={
                                            tagErrorKeys.length
                                                ? errors[tagErrorKeys[0]!]
                                                : undefined
                                        }
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
                                        edited.current = true;
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
                    <InputError message={errors._refusal} />
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
                            edited.current = true;
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
                        edited.current = true;
                        form.setData('client_id', String(created.id));
                        setNewClientName(null);
                    }}
                />
            )}
        </>
    );
}
