import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { MailConfigurationNotice } from '@/components/mail-configuration-notice';
import { PageHeader } from '@/components/page-header';
import type { SettingsPage } from '@/components/recipient-settings/model';
import { RecipientSettings } from '@/components/recipient-settings/recipient-settings';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Disclosure } from '@/components/ui/disclosure';
import { useHttpRequest } from '@/hooks/use-http-request';
import { dismiss as dismissMigrationReport } from '@/routes/settings/notifications/team/migration-report';

/** The team's notification channels: Owners and Admins (design §7.1). */
export default function TeamNotifications({
    settings,
}: {
    settings: SettingsPage;
}) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    // The Owner's own dismissal hides the report at once; it is stored for the instance.
    const [dismissed, setDismissed] = useState(false);
    const dismissal = useHttpRequest();
    const report = dismissed ? null : settings.migration_report;
    const dismiss = async () => {
        try {
            await dismissal.delete(dismissMigrationReport.url());
            setDismissed(true);
        } catch {
            toast.error(t('recipient_settings.migration.dismiss_failed'));
        }
    };
    return (
        <>
            <Head title={t('Notifications')} />
            <PageHeader
                title={t('Notifications')}
                description={t('recipient_settings.team.description')}
                notices={
                    <>
                        <MailConfigurationNotice />
                        {report !== null && report.length > 0 && (
                            <Alert
                                tone="info"
                                action={
                                    auth.canManageInstance ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={dismissal.processing}
                                            onClick={() => void dismiss()}
                                        >
                                            {t(
                                                'recipient_settings.migration.dismiss',
                                            )}
                                        </Button>
                                    ) : undefined
                                }
                            >
                                <AlertTitle>
                                    {t('recipient_settings.migration.title')}
                                </AlertTitle>
                                <AlertDescription>
                                    <Disclosure
                                        summary={t(
                                            'recipient_settings.migration.summary',
                                            { count: report.length },
                                        )}
                                    >
                                        <ul className="mt-2 list-disc space-y-1 pl-5">
                                            {report.map((line) => (
                                                <li key={line}>{line}</li>
                                            ))}
                                        </ul>
                                    </Disclosure>
                                </AlertDescription>
                            </Alert>
                        )}
                    </>
                }
            />
            <RecipientSettings
                page={settings}
                scopeDescription={t('recipient_settings.team.scope', {
                    count: settings.sites.length,
                })}
            />
        </>
    );
}
