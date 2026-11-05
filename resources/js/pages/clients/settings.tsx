import {
    ClientFormFields,
    clientFormValues,
} from '@/components/client-form-fields';
import {
    FormSection,
    FormActions,
    FormSecondaryAction,
} from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { ClientHeader } from '@/components/client-header';
import { PageContainer } from '@/components/page-container';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { ClientAvatarSettings } from '@/components/client-avatar-settings';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { archive, store, update } from '@/routes/clients';

export default function ClientSettings({
    client,
    avatar_sites,
    timezones,
}: Heptapod.Data.Ui.ClientSettingsPageData) {
    const { t } = useTranslation();
    const [archiveOpen, setArchiveOpen] = useState(false);
    const creating = client === null;
    const form = useForm(clientFormValues(client));
    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        };
        if (client) {
            form.patch(update.url(client.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <>
            <Head title={t(creating ? 'New client' : 'Client settings')} />
            <PageContainer>
                {client ? (
                    <ClientHeader
                        client={client}
                        section="settings"
                        canManage
                    />
                ) : (
                    <PageHeader
                        title={t('New client')}
                        description={t('pages.client_settings.description')}
                    />
                )}
                <form
                    noValidate
                    id="client-settings-form"
                    onSubmit={save}
                    aria-busy={form.processing}
                    className="space-y-4"
                >
                    <ClientFormFields
                        data={form.data}
                        errors={form.errors}
                        setData={form.setData}
                        timezones={timezones}
                    />
                    <FormActions
                        data-client-savebar
                        status={
                            <>
                                {form.processing
                                    ? t('Saving…')
                                    : form.isDirty
                                      ? t('Unsaved changes')
                                      : t('No unsaved changes')}
                            </>
                        }
                    >
                        <FormSecondaryAction
                            size="sm"
                            disabled={!form.isDirty || form.processing}
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                            }}
                        >
                            {t('Discard')}
                        </FormSecondaryAction>
                        <Button
                            id="client-settings-save"
                            type="submit"
                            size="sm"
                            disabled={!form.isDirty || form.processing}
                        >
                            {t(creating ? 'Create client' : 'Save')}
                        </Button>
                    </FormActions>
                </form>
                {client && (
                    <ClientAvatarSettings
                        client={client}
                        sites={avatar_sites}
                    />
                )}
                {client && !client.is_internal && !client.archived_at && (
                    <FormSection
                        title={t('Archive client')}
                        description={t(
                            'Stops all monitoring for this client and hides it from active views.',
                        )}
                    >
                        <Button
                            variant="outline"
                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => setArchiveOpen(true)}
                        >
                            {t('Archive client')}
                        </Button>
                        {/* Archiving has no undo path (no unarchive route or action), so it stays destructive. */}
                        <ConfirmDialog
                            open={archiveOpen}
                            onOpenChange={setArchiveOpen}
                            destructive
                            title={t('Archive client?')}
                            description={t(
                                'Archiving pauses monitoring for all sites. History remains available.',
                            )}
                            confirmLabel={t('Archive client')}
                            onConfirm={() =>
                                confirmVisit((callbacks) =>
                                    router.post(
                                        archive.url(client.id),
                                        {},
                                        callbacks,
                                    ),
                                )
                            }
                        />
                    </FormSection>
                )}
                {client?.is_internal && (
                    <p className="text-sm text-muted-foreground">
                        {t('The Internal client cannot be archived.')}
                    </p>
                )}
                {client?.archived_at && (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'This client is archived. Monitoring is stopped; history remains available.',
                        )}
                    </p>
                )}
            </PageContainer>
        </>
    );
}
