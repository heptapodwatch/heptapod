import InputError from '@/components/input-error';
import { IncidentEpisode } from '@/components/incident-episode';
import { PageContainer } from '@/components/page-container';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Send } from 'lucide-react';
import { PageHeaderAction } from '@/components/page-header-actions';
import { SendToClientSheet } from '@/components/send-to-client-sheet';
import { useTranslation } from 'react-i18next';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFormat } from '@/hooks/use-format';
import { useRequestedRun } from '@/hooks/use-requested-run';
import { toast } from 'sonner';
import { isCoalescedStateChange, useLiveReload } from '@/hooks/use-live';
import { checkTypes } from '@/lib/check-types';
import { alertPreview, index as incidentsIndex } from '@/routes/incidents';

type Preview = {
    subject: string;
    html: string;
    destination: {
        id: number;
        label: string;
        address: string;
        locale: string;
        timezone: string;
    };
};

/** One incident page for open and resolved incidents (heptapod-ui-rules Incident episode). */
export default function IncidentDetail(
    page: Heptapod.Data.Ui.IncidentDetailPageData,
) {
    const {
        site,
        header,
        incident,
        runs,
        timeline,
        evidence,
        current_presentation,
    } = page;
    const { t } = useTranslation();
    const format = useFormat();
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [sendOpen, setSendOpen] = useState(false);
    const { auth } = usePage().props;
    const previewRequest = useRef<AbortController | null>(null);
    useEffect(() => () => previewRequest.current?.abort(), []);
    const isOpen = incident.resolved_at === null;
    const administrativeResolution =
        evidence?.recovery_summary?.startsWith('incidents.resolution.') ??
        false;
    const isSiteOutage =
        incident.check === 'uptime' && incident.cause === 'failed';
    const check = site.checks.find((item) => item.key === incident.check);
    useLiveReload(
        ['state.changed'],
        [
            'site',
            'header',
            'incident',
            'can_unsnooze',
            'can_snooze',
            'runs',
            'evidence',
            'opening_presentation',
            'current_presentation',
            'other_deliveries',
            'opening_delivery_explanation',
            'maintenance',
            'current_evidence',
            'current_findings',
            'timeline',
            'related_incidents',
        ],
        (signal) =>
            signal.ids.site === site.id ||
            signal.ids.client === site.client_id ||
            isCoalescedStateChange(signal),
        true,
        {
            missingResource: {
                url: incidentsIndex.url(),
                message: t('Site deleted'),
            },
        },
    );
    const requestedRun = useRequestedRun(site.id, check?.id ?? null);
    const finishedRun = requestedRun.finished;
    // The own run is applied once the live reload after its completion brought it: its run row (open incident)
    // or the timeline event it produced (a resolved incident's runs end before its recovery run).
    const ownRun =
        finishedRun &&
        (finishedRun.checkRunId === null ||
            runs.some((run) => run.id === finishedRun.checkRunId) ||
            timeline.some(
                (entry) => entry.event?.run_id === finishedRun.checkRunId,
            ))
            ? finishedRun
            : null;
    const showPreview = async (eventId: number) => {
        previewRequest.current?.abort();
        const controller = new AbortController();
        previewRequest.current = controller;
        setPreviewOpen(true);
        setPreviewLoading(true);
        setPreview(null);
        setPreviewError(false);
        try {
            const response = await fetch(
                alertPreview.url({ incident: incident.id, event: eventId }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            );
            if (!response.ok)
                throw new Error(`Alert preview failed: ${response.status}`);
            const value: unknown = await response.json();
            if (
                value === null ||
                typeof value !== 'object' ||
                !('subject' in value) ||
                typeof value.subject !== 'string' ||
                !('html' in value) ||
                typeof value.html !== 'string' ||
                !('destination' in value) ||
                value.destination === null ||
                typeof value.destination !== 'object' ||
                !('address' in value.destination) ||
                typeof value.destination.address !== 'string'
            )
                throw new Error('Alert preview response is invalid.');
            if (!controller.signal.aborted) setPreview(value as Preview);
        } catch (error) {
            if (!controller.signal.aborted) {
                console.error(error);
                setPreviewError(true);
            }
        } finally {
            if (previewRequest.current === controller) setPreviewLoading(false);
        }
    };
    // An incomplete successful crawl can leave findings unresolved; its OK result is not the incident title.
    const currentHeadline =
        isOpen &&
        runs[0]?.result ===
            (incident.cause === 'failed' ? 'failed' : 'errored-or-timed-out') &&
        !incident.held_after_recovery &&
        incident.uptime_parent_incident_id === null
            ? current_presentation
                ? format.presentation(current_presentation)
                : runs[0]?.summary
                  ? format.summary(runs[0].summary, runs[0].summary_params)
                  : null
            : null;
    let heldHeadline: string | null = null;
    if (incident.held_after_recovery) {
        if (incident.next_run_at === null)
            throw new Error('A held incident needs its next run.');
        heldHeadline = t('Re-checking after recovery · next run :time', {
            time: format.timestamp(incident.next_run_at),
        });
    }
    const headline =
        heldHeadline ??
        currentHeadline ??
        t(
            !isOpen
                ? administrativeResolution
                    ? '{{site}}: {{check}} closed'
                    : isSiteOutage
                      ? '{{site}} is back up.'
                      : '{{site}}: {{check}} resolved'
                : incident.uptime_parent_incident_id !== null
                  ? '{{site}}: affected by the uptime outage'
                  : incident.cause === 'errored'
                    ? '{{site}} could not be checked.'
                    : isSiteOutage
                      ? '{{site}} is down.'
                      : '{{site}} has a failing check.',
            { site: header.name, check: t(checkTypes[incident.check].label) },
        );
    // Every outcome of this tab's own run replaces the "still running" toast shown while it ran.
    useEffect(() => {
        if (requestedRun.state.phase === 'running') {
            toast.loading(
                t(
                    'Still running: the result appears here when the check finishes.',
                ),
                { id: `own-run-${requestedRun.state.attemptId}` },
            );
        }
    }, [requestedRun.state]);
    useEffect(() => {
        if (!ownRun) return;
        const id = `own-run-${ownRun.attemptId}`;
        if (ownRun.result === null) {
            toast.warning(t('The check did not run.'), { id });
        } else if (ownRun.result === 'errored-or-timed-out') {
            if (!check?.summary) {
                throw new Error('A check error result has no summary.');
            }
            toast.error(t('Check error'), {
                id,
                description: format.summary(
                    check.summary,
                    check.summary_params,
                ),
            });
        } else if (ownRun.result === 'succeeded') {
            toast.success(
                isOpen
                    ? t('Check passed: {{headline}}', { headline })
                    : t('Check passed: incident resolved'),
                { id },
            );
        } else {
            toast.warning(t('Still failing: {{headline}}', { headline }), {
                id,
            });
        }
        requestedRun.consume();
    }, [ownRun]);
    return (
        <>
            <Head title={header.name} />
            <PageContainer>
                <IncidentEpisode
                    page={page}
                    headline={headline}
                    requestedRun={requestedRun}
                    ownRun={ownRun}
                    onPreview={(eventId) => void showPreview(eventId)}
                    clientAction={
                        auth.canManageChannels ? (
                            <PageHeaderAction
                                variant="outline"
                                onClick={() => setSendOpen(true)}
                            >
                                <Send aria-hidden />
                                {t('client_messages.send_action')}
                            </PageHeaderAction>
                        ) : undefined
                    }
                />
                {sendOpen && (
                    <SendToClientSheet
                        open
                        onOpenChange={setSendOpen}
                        client={page.client}
                        subject={{ kind: 'incident', incident_id: incident.id }}
                        initialDesign={page.mail_design}
                    />
                )}
            </PageContainer>
            <Dialog
                open={previewOpen}
                onOpenChange={(open) => {
                    if (!open) previewRequest.current?.abort();
                    setPreviewOpen(open);
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{t('Alert email preview')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Email preview generated from this event. Historical sent content may differ.',
                            )}
                            {preview && (
                                <span className="mt-1 block">
                                    {`${preview.destination.label} · ${preview.destination.address} · ${preview.destination.timezone}`}
                                </span>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {previewLoading && (
                        <p className="text-sm text-muted-foreground">
                            {t('Loading')}
                        </p>
                    )}
                    <InputError
                        message={
                            previewError
                                ? 'Alert preview could not load.'
                                : null
                        }
                    />
                    {preview && (
                        <>
                            <p className="text-sm font-medium break-words">
                                {preview.subject}
                            </p>
                            <iframe
                                sandbox=""
                                srcDoc={preview.html}
                                title={t('Alert email preview')}
                                className="h-[55vh] w-full rounded-md border bg-white"
                            />
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
