import { AbsoluteTime } from '@/components/absolute-time';
import { SectionHeading } from '@/components/section-heading';
import { PageHeader } from '@/components/page-header';
import {
    FormSection,
    FormActions,
    FormSecondaryAction,
} from '@/components/form-section';
import { SiteClientField, SiteUrlField } from '@/components/site-fields';
import { PageContainer } from '@/components/page-container';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { index as sitesIndex, store as sitesStore } from '@/routes/sites';
import type { CheckKey, FieldValue } from '@/types/monitoring';
import {
    eligibleSiteCheck,
    siteCreateDraft,
    type SiteCreateStep,
} from '@/lib/site-create-draft';
import { Stepper } from '@/components/stepper';
import { SiteChecksList } from '@/components/site-checks-list';
import { CheckSettingsSheet } from '@/components/check-settings-sheet';
import { TextLink } from '@/components/text-link';
import { team as teamNotifications } from '@/routes/settings/notifications';
import { ClientCreateSheet } from '@/components/client-create-sheet';
import { useSiteUrlProbe } from '@/hooks/use-site-url-probe';

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
    const [step, setStep] = useState<SiteCreateStep>(
        initial.draft?.step ?? 'address',
    );
    const changedChecks = useRef(
        new Set<CheckKey>(initial.draft?.changedChecks),
    );
    const form = useForm({
        url: initial.draft?.url ?? '',
        client_id:
            initial.draft?.client_id ??
            String(selected_client_id ?? clients[0]?.id ?? ''),
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
                            step,
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
    }, [
        form.data,
        step,
        openChecks,
        draftKey,
        draftUnavailable,
        clients,
        checks,
    ]);
    const clearDraft = () => {
        draftCleared.current = true;
        try {
            localStorage.removeItem(draftKey);
        } catch {
            setDraftUnavailable(true);
        }
    };
    const { probe, probeState, probeError, runProbe, clearProbe } =
        useSiteUrlProbe(form.data.url, true);
    const canonicalUrl = probe?.normalised_url ?? form.data.url;
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
    const isEligible = (check: (typeof checks)[number]) =>
        eligibleSiteCheck(check, form.data.url);
    useEffect(() => {
        if (initial.draft?.step === 'checks' && initial.draft.url)
            void runProbe();
    }, []);
    const navigate = (target: SiteCreateStep) => {
        if (target !== 'address' && !form.data.url.trim()) {
            form.setError(
                'url',
                t('Enter a valid HTTP or HTTPS URL without credentials.'),
            );
            setStep('address');
            return;
        }
        form.clearErrors('url');
        if (target === 'checks' && !form.data.client_id) {
            form.setError('client_id', t('Select a client.'));
            setStep('client');
            return;
        }
        form.clearErrors('client_id');
        setStep(target);
        if (target === 'checks' && probeState === 'idle') void runProbe();
    };
    const submit = (event: React.SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        const eligibleChecks = checks
            .filter(
                (check) =>
                    isEligible(check) && form.data.checks.includes(check.key),
            )
            .map((check) => check.key);
        if (!eligibleChecks.length) {
            form.setError('checks', t('Select at least one check.'));
            return;
        }
        if (
            eligibleChecks.includes('performance') &&
            !eligibleChecks.includes('uptime')
        ) {
            form.setError(
                'checks',
                t('Performance uses Uptime results. Enable Uptime first.'),
            );
            return;
        }
        form.clearErrors('checks');
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
        form.transform(({ client_id }) => ({
            url: form.data.url,
            client_id,
            checks: eligibleChecks,
            ...settings,
        }));
        form.post(sitesStore.url(), {
            onSuccess: clearDraft,
            onError: (errors) => {
                if (errors.url) setStep('address');
                else if (errors.client_id) setStep('client');
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
    return (
        <>
            <Head title={t('Add site')} />
            <PageContainer>
                <PageHeader
                    title={t('Add site')}
                    description={t('pages.add_site.description')}
                />
                <Stepper
                    label={t('Add site steps')}
                    steps={[t('Address'), t('Client'), t('Checks')]}
                    current={(['address', 'client', 'checks'] as const).indexOf(
                        step,
                    )}
                    disabled={form.processing}
                    onStepChange={(index) =>
                        navigate(
                            (['address', 'client', 'checks'] as const)[index]!,
                        )
                    }
                />
                <form
                    noValidate
                    id="site-create-form"
                    onSubmit={(event) => {
                        if (step === 'checks') return submit(event);
                        event.preventDefault();
                        navigate(step === 'address' ? 'client' : 'checks');
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
                    {step === 'address' && (
                        <FormSection
                            title={t('Address')}
                            description={t(
                                'Add the address you want to monitor.',
                            )}
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
                                probe={probe}
                                probeState={probeState}
                                probeError={probeError}
                                onUseAddress={(url) => {
                                    clearProbe();
                                    form.setData('url', url);
                                }}
                                error={form.errors.url}
                                help={t(
                                    'We check the address before adding the site',
                                )}
                            />
                        </FormSection>
                    )}
                    {step === 'client' && (
                        <FormSection
                            title={t('Client')}
                            description={t('Choose a client for this site.')}
                        >
                            <SiteClientField
                                clients={clients}
                                onCreateClient={
                                    can_create_site
                                        ? setNewClientName
                                        : undefined
                                }
                                value={form.data.client_id}
                                onValueChange={(value) =>
                                    form.setData('client_id', value)
                                }
                                required
                                error={form.errors.client_id}
                            />
                        </FormSection>
                    )}
                    {step === 'checks' && (
                        <FormSection
                            title={t('Checks')}
                            description={t('Recommended checks are selected.')}
                            detail={t(
                                'Open Settings to change their defaults.',
                            )}
                        >
                            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
                                <div className="min-w-0 space-y-3">
                                    <SectionHeading level={3}>
                                        {t('What should we watch on {{url}}?', {
                                            url: probe?.normalised_url
                                                ? new URL(probe.normalised_url)
                                                      .hostname
                                                : form.data.url,
                                        })}
                                    </SectionHeading>
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
                                                    {t(
                                                        'add_site.no_rules.title',
                                                    )}
                                                </AlertTitle>
                                                <AlertDescription>
                                                    {t(
                                                        'add_site.no_rules.body',
                                                    )}
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
                                                    (check) =>
                                                        check.recommended,
                                                )
                                                .map((check) => check.key)
                                        }
                                        selected={form.data.checks}
                                        settings={form.data.settings}
                                        canManage={can_create_site}
                                        onSettings={(key) =>
                                            setOpenChecks([key])
                                        }
                                        onToggle={(key, enabled) => {
                                            changedChecks.current.add(key);
                                            if (key === 'uptime' && !enabled)
                                                changedChecks.current.add(
                                                    'performance',
                                                );
                                            form.setData(
                                                'checks',
                                                enabled
                                                    ? [...form.data.checks, key]
                                                    : form.data.checks.filter(
                                                          (selected) =>
                                                              selected !==
                                                                  key &&
                                                              !(
                                                                  key ===
                                                                      'uptime' &&
                                                                  selected ===
                                                                      'performance'
                                                              ),
                                                      ),
                                            );
                                        }}
                                    />
                                </div>
                                <aside
                                    className="h-fit space-y-3 rounded-lg border p-4"
                                    aria-live="polite"
                                >
                                    <SectionHeading level={3}>
                                        {t('Detected')}
                                    </SectionHeading>
                                    {probeState === 'loading' && (
                                        <div
                                            className="space-y-3"
                                            aria-label={t('Checking site')}
                                        >
                                            <p className="text-sm">
                                                {t('Checking site')}
                                            </p>
                                            <Skeleton className="h-4 w-full" />
                                            <Skeleton className="h-4 w-3/4" />
                                        </div>
                                    )}
                                    {probeState === 'done' && probe && (
                                        <dl className="space-y-3 text-sm">
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    {t('URL')}
                                                </dt>
                                                <dd className="break-all">
                                                    {canonicalUrl}
                                                </dd>
                                            </div>
                                            {probe.final_url &&
                                                probe.final_url !==
                                                    canonicalUrl && (
                                                    <div>
                                                        <dt className="text-muted-foreground">
                                                            {t('Redirects to')}
                                                        </dt>
                                                        <dd className="break-all">
                                                            {probe.final_url}
                                                        </dd>
                                                    </div>
                                                )}
                                            {probe.status !== null && (
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('HTTP status')}
                                                    </dt>
                                                    <dd>{probe.status}</dd>
                                                </div>
                                            )}
                                            {probe.server && (
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('Server')}
                                                    </dt>
                                                    <dd>{probe.server}</dd>
                                                </div>
                                            )}
                                            {probe.certificate_issuer && (
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('Certificate')}
                                                    </dt>
                                                    <dd>
                                                        {
                                                            probe.certificate_issuer
                                                        }
                                                        {probe.certificate_expires_at && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                <AbsoluteTime
                                                                    value={
                                                                        probe.certificate_expires_at
                                                                    }
                                                                    mode="calendarDate"
                                                                />
                                                            </>
                                                        )}
                                                    </dd>
                                                </div>
                                            )}
                                            {probe.sitemap_url && (
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('Sitemap')}
                                                    </dt>
                                                    <dd className="break-all">
                                                        {probe.sitemap_url}
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>
                                    )}
                                    {probeState === 'error' && (
                                        <div
                                            role="alert"
                                            className="space-y-2 text-sm"
                                        >
                                            <p>
                                                {t(
                                                    'Site URL could not be detected',
                                                )}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {t(
                                                    'Check the URL or retry. You can still add the site.',
                                                )}
                                            </p>
                                        </div>
                                    )}
                                    {probeState !== 'loading' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => void runProbe(true)}
                                        >
                                            {t('Retry')}
                                        </Button>
                                    )}
                                </aside>
                            </div>
                        </FormSection>
                    )}
                    <FormActions
                        status={
                            <>
                                {t('Step :step of :total', {
                                    step:
                                        step === 'address'
                                            ? 1
                                            : step === 'client'
                                              ? 2
                                              : 3,
                                    total: 3,
                                })}
                                {step === 'checks' && (
                                    <>
                                        {' '}
                                        ·{' '}
                                        {t('add_site.count.footer', {
                                            count: checks.filter(
                                                (check) =>
                                                    isEligible(check) &&
                                                    form.data.checks.includes(
                                                        check.key,
                                                    ) &&
                                                    (check.key !==
                                                        'performance' ||
                                                        form.data.checks.includes(
                                                            'uptime',
                                                        )),
                                            ).length,
                                        })}
                                    </>
                                )}
                            </>
                        }
                        back={
                            step !== 'address' && (
                                <FormSecondaryAction
                                    onClick={() =>
                                        setStep(
                                            step === 'checks'
                                                ? 'client'
                                                : 'address',
                                        )
                                    }
                                >
                                    {t('Back')}
                                </FormSecondaryAction>
                            )
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
                            {t(step === 'checks' ? 'Add site' : 'Next')}
                        </Button>
                    </FormActions>
                </form>
            </PageContainer>
            {checks
                .filter(
                    (check) =>
                        step === 'checks' &&
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
                        onClose={() => setOpenChecks([])}
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
                    onCreated={(client) => {
                        setCreatedClients((current) => [...current, client]);
                        form.setData('client_id', String(client.id));
                        setNewClientName(null);
                    }}
                />
            )}
        </>
    );
}
