import {
    ClientFormFields,
    clientFormValues,
} from '@/components/client-form-fields';
import {
    FormSection,
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
} from '@/components/form-section';
import { PageHeader } from '@/components/page-header';
import { ClientHeader } from '@/components/client-header';
import { PageContainer } from '@/components/page-container';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ClientAvatarSettings,
    type ClientAvatarDraft,
} from '@/components/client-avatar-settings';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { RadioCards } from '@/components/ui/radio-cards';
import { CheckboxField } from '@/components/ui/checkbox';
import {
    EntityCombobox,
    clientComboboxOptions,
} from '@/components/entity-combobox';
import { Label } from '@/components/ui/label';
import { archive, restore, store, update } from '@/routes/clients';
import { useFormat } from '@/hooks/use-format';

export default function ClientSettings({
    client,
    avatar_sites,
    timezones,
    clients,
    site_count,
    archive_paused_site_count,
}: Heptapod.Data.Ui.ClientSettingsPageData) {
    const { t } = useTranslation();
    const format = useFormat();
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [restoreOpen, setRestoreOpen] = useState(false);
    const archiveForm = useForm<{
        site_action: 'archive' | 'move';
        client_id: string;
    }>({
        site_action: 'archive',
        client_id: String(
            clients.find((choice) => choice.is_internal)?.id ?? '',
        ),
    });
    const restoreForm = useForm({ resume_sites: true });
    const creating = client === null;
    const form = useForm({
        ...clientFormValues(client),
        avatar: null as ClientAvatarDraft,
    });
    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // PHP parses a multipart body (an avatar upload) only for POST, so the update is a spoofed PATCH.
        form.transform(({ avatar, name, ...values }) => ({
            ...values,
            ...(!client?.is_internal && { name }),
            ...avatar,
            ...(client && { _method: 'patch' }),
        }));
        form.post(client ? update.url(client.id) : store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('avatar', null);
                form.setDefaults();
            },
        });
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
                        internal={client?.is_internal}
                    />
                    {client && (
                        <ClientAvatarSettings
                            client={client}
                            sites={avatar_sites}
                            draft={form.data.avatar}
                            onDraftChange={(avatar) =>
                                form.setData('avatar', avatar)
                            }
                            errors={form.errors}
                            disabled={form.processing}
                        />
                    )}
                    <FormActions
                        data-client-savebar
                        status={
                            <FormSaveStatus
                                dirty={form.isDirty}
                                processing={form.processing}
                            />
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
                            form="client-settings-form"
                            size="sm"
                            disabled={!form.isDirty || form.processing}
                        >
                            {t(creating ? 'Create client' : 'Save')}
                        </Button>
                    </FormActions>
                </form>
                {client && !client.is_internal && !client.archived_at && (
                    <FormSection
                        title={t('Archive client')}
                        description={t(
                            'Hide this client from active views; its history is kept and you can restore it later.',
                        )}
                    >
                        <Button
                            variant="outline"
                            onClick={() => {
                                archiveForm.resetAndClearErrors();
                                setArchiveOpen(true);
                            }}
                        >
                            {t('Archive client')}
                        </Button>
                        <ConfirmDialog
                            open={archiveOpen}
                            onOpenChange={setArchiveOpen}
                            title={t('Archive client?')}
                            description={t(
                                'Hide this client from active views; its history is kept and you can restore it later.',
                            )}
                            confirmLabel={t('Archive client')}
                            onConfirm={() =>
                                confirmVisit((callbacks) =>
                                    archiveForm.post(
                                        archive.url(client.id),
                                        callbacks,
                                    ),
                                )
                            }
                        >
                            {site_count > 0 && (
                                <div className="space-y-4">
                                    <RadioCards
                                        label={t('What happens to the sites?')}
                                        value={archiveForm.data.site_action}
                                        onChange={(value) =>
                                            archiveForm.setData(
                                                'site_action',
                                                value,
                                            )
                                        }
                                        disabled={archiveForm.processing}
                                        invalid={
                                            !!archiveForm.errors.site_action
                                        }
                                        describedBy={
                                            fieldErrorProps(
                                                'archive-site-action',
                                                archiveForm.errors.site_action,
                                            )['aria-describedby']
                                        }
                                        options={[
                                            {
                                                value: 'archive',
                                                label: t(
                                                    'Archive the sites too',
                                                ),
                                                description: t(
                                                    'Pause the sites and hide them with the client; keep their history.',
                                                ),
                                            },
                                            {
                                                value: 'move',
                                                label: t(
                                                    'Move the sites to another client',
                                                ),
                                                description: t(
                                                    'Keep each site running or paused as it is; transfer its access and history.',
                                                ),
                                            },
                                        ]}
                                    />
                                    <InputError
                                        field="archive-site-action"
                                        message={archiveForm.errors.site_action}
                                    />
                                    {archiveForm.data.site_action ===
                                        'move' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="archive-target-client">
                                                {t('Client')}
                                            </Label>
                                            <EntityCombobox
                                                id="archive-target-client"
                                                options={clientComboboxOptions(
                                                    clients,
                                                )}
                                                value={
                                                    archiveForm.data.client_id
                                                }
                                                onValueChange={(value) =>
                                                    archiveForm.setData(
                                                        'client_id',
                                                        value,
                                                    )
                                                }
                                                disabled={
                                                    archiveForm.processing
                                                }
                                                invalid={
                                                    !!archiveForm.errors
                                                        .client_id
                                                }
                                                describedBy={
                                                    fieldErrorProps(
                                                        'archive-target-client',
                                                        archiveForm.errors
                                                            .client_id,
                                                    )['aria-describedby']
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'archive_client.move_consequences',
                                                )}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t('archive_client.move_wait')}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'archive_client.move_progress',
                                                )}
                                            </p>
                                        </div>
                                    )}
                                    <InputError
                                        field="archive-target-client"
                                        message={archiveForm.errors.client_id}
                                    />
                                </div>
                            )}
                        </ConfirmDialog>
                    </FormSection>
                )}
                {client?.is_internal && (
                    <p className="text-sm text-muted-foreground">
                        {t('No client cannot be archived.')}
                    </p>
                )}
                {client?.archived_at && (
                    <FormSection
                        title={t('Restore client')}
                        description={t(
                            'Make this client and its sites visible and manageable again.',
                        )}
                    >
                        <Button
                            variant="outline"
                            onClick={() => {
                                restoreForm.resetAndClearErrors();
                                setRestoreOpen(true);
                            }}
                        >
                            {t('Restore client')}
                        </Button>
                        <ConfirmDialog
                            open={restoreOpen}
                            onOpenChange={setRestoreOpen}
                            title={t('Restore client?')}
                            description={t('restore_client.description', {
                                count: site_count,
                                formattedCount: format.number(site_count),
                            })}
                            confirmLabel={t('Restore client')}
                            onConfirm={() =>
                                confirmVisit((callbacks) =>
                                    restoreForm.post(
                                        restore.url(client.id),
                                        callbacks,
                                    ),
                                )
                            }
                        >
                            {archive_paused_site_count > 0 ? (
                                <CheckboxField
                                    id="restore-resume-sites"
                                    checked={restoreForm.data.resume_sites}
                                    onCheckedChange={(checked) =>
                                        restoreForm.setData(
                                            'resume_sites',
                                            checked === true,
                                        )
                                    }
                                    disabled={restoreForm.processing}
                                    {...fieldErrorProps(
                                        'restore-resume-sites',
                                        restoreForm.errors.resume_sites,
                                    )}
                                >
                                    {t('restore_client.resume', {
                                        count: archive_paused_site_count,
                                        formattedCount: format.number(
                                            archive_paused_site_count,
                                        ),
                                    })}
                                </CheckboxField>
                            ) : site_count > 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Sites stay paused; resume them on each site.',
                                    )}
                                </p>
                            ) : null}
                            <InputError
                                field="restore-resume-sites"
                                message={restoreForm.errors.resume_sites}
                            />
                        </ConfirmDialog>
                    </FormSection>
                )}
            </PageContainer>
        </>
    );
}
