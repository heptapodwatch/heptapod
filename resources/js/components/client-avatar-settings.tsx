import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ClientMark } from '@/components/client-mark';
import { FormSection } from '@/components/form-section';
import { ImageDropzone } from '@/components/image-dropzone';
import { Label } from '@/components/ui/label';
import {
    EntityCombobox,
    siteComboboxOptions,
} from '@/components/entity-combobox';
import { update } from '@/routes/clients';

export function ClientAvatarSettings({
    client,
    sites,
}: {
    client: Heptapod.Data.Ui.ClientData;
    sites: Heptapod.Data.Ui.ClientAvatarSiteData[];
}) {
    const { t } = useTranslation();
    const [file, setFile] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    const [saved, setSaved] = useState(false);
    const save = (attributes: {
        logo?: File;
        remove_logo?: boolean;
        avatar_site_id?: number | null;
    }) => {
        setError(undefined);
        setSaved(false);
        router.post(
            update.url(client.id),
            { _method: 'patch', ...attributes },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onSuccess: () => {
                    setFile(null);
                    setSaved(true);
                },
                onError: (errors) => {
                    setFile(null);
                    setError(Object.values(errors).join(' '));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };
    const hasOverride =
        client.avatar.source === 'uploaded' || client.avatar_site_id !== null;
    return (
        <FormSection
            title={t('Avatar')}
            description={t('Shown in lists and headers.')}
            detail={t(
                'Automatic uses a non-generic website icon, or initials.',
            )}
        >
            <div className="flex items-center gap-3">
                <ClientMark client={client} />
                <p className="text-sm text-muted-foreground">
                    {client.avatar.source === 'uploaded'
                        ? t('Uploaded')
                        : client.avatar.source === 'chosen-site'
                          ? t('Chosen icon · :site', {
                                site: client.avatar.site_host,
                            })
                          : client.avatar.source === 'automatic'
                            ? t('Automatic · :site', {
                                  site: client.avatar.site_host,
                              })
                            : client.avatar.source === 'generic-initials'
                              ? t(
                                    'Automatic · initials (site icons are generic)',
                                )
                              : t('Initials')}
                </p>
            </div>
            <ImageDropzone
                value={file}
                error={error}
                disabled={processing}
                onChange={(image) => {
                    setFile(image);
                    save({ logo: image });
                }}
                onReset={
                    hasOverride ? () => save({ remove_logo: true }) : undefined
                }
            />
            {sites.length > 0 && (
                <div className="space-y-2">
                    <Label htmlFor="client-avatar-site">
                        {t('Use icon from…')}
                    </Label>
                    <EntityCombobox
                        id="client-avatar-site"
                        options={[
                            { value: 'automatic', label: t('Automatic') },
                            ...siteComboboxOptions(sites),
                        ]}
                        value={
                            client.avatar.source === 'chosen-site'
                                ? String(client.avatar.site_id)
                                : 'automatic'
                        }
                        disabled={processing}
                        onValueChange={(value) =>
                            save(
                                value === 'automatic'
                                    ? { remove_logo: true }
                                    : { avatar_site_id: Number(value) },
                            )
                        }
                    />
                </div>
            )}
            <p role="status" className="text-xs text-muted-foreground">
                {processing
                    ? t('Saving…')
                    : saved
                      ? t('Avatar updated.')
                      : t('Avatar changes are saved immediately.')}
            </p>
        </FormSection>
    );
}
