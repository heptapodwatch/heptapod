import { useTranslation } from 'react-i18next';
import { ClientMark } from '@/components/client-mark';
import { FormSection } from '@/components/form-section';
import { ImageDropzone } from '@/components/image-dropzone';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    EntityCombobox,
    siteComboboxOptions,
} from '@/components/entity-combobox';

/** An unsaved avatar change, sent with the client form's Save: an upload, a site icon, or back to automatic. null = unchanged. */
export type ClientAvatarDraft =
    | { logo: File }
    | { avatar_site_id: number }
    | { remove_logo: true }
    | null;

export function ClientAvatarSettings({
    client,
    sites,
    draft,
    onDraftChange,
    errors,
    disabled,
}: {
    client: Heptapod.Data.Ui.ClientData;
    sites: Heptapod.Data.Ui.ClientAvatarSiteData[];
    draft: ClientAvatarDraft;
    onDraftChange: (draft: ClientAvatarDraft) => void;
    /** The client form's server errors; the avatar fields are not draft keys. */
    errors: Partial<Record<string, string>>;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const savedOverride =
        client.avatar.source === 'uploaded' || client.avatar_site_id !== null;
    const savedSiteId =
        client.avatar.source === 'chosen-site' ? client.avatar.site_id : null;
    const override = draft ? !('remove_logo' in draft) : savedOverride;
    // Choosing what is already saved clears the draft instead of sending a no-op.
    const choose = (siteId: number | null) =>
        onDraftChange(
            siteId === null
                ? savedOverride
                    ? { remove_logo: true }
                    : null
                : siteId === savedSiteId
                  ? null
                  : { avatar_site_id: siteId },
        );
    const chosenSiteId = draft
        ? 'avatar_site_id' in draft
            ? draft.avatar_site_id
            : null
        : savedSiteId;
    return (
        <FormSection
            title={t('Avatar')}
            description={t('Shown in lists and headers.')}
            detail={t(
                client.is_internal
                    ? 'No client uses a neutral mark unless you choose an image.'
                    : 'Automatic uses a non-generic website icon, or initials.',
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
                              : t(
                                    client.is_internal
                                        ? 'Neutral mark'
                                        : 'Initials',
                                )}
                </p>
            </div>
            <ImageDropzone
                value={draft && 'logo' in draft ? draft.logo : null}
                error={errors.logo}
                disabled={disabled}
                onChange={(logo) => onDraftChange({ logo })}
                onReset={override ? () => choose(null) : undefined}
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
                            chosenSiteId === null
                                ? 'automatic'
                                : String(chosenSiteId)
                        }
                        disabled={disabled}
                        invalid={!!errors.avatar_site_id}
                        describedBy={
                            fieldErrorProps(
                                'client-avatar-site',
                                errors.avatar_site_id,
                            )['aria-describedby']
                        }
                        onValueChange={(value) =>
                            choose(value === 'automatic' ? null : Number(value))
                        }
                    />
                    <InputError
                        field="client-avatar-site"
                        message={errors.avatar_site_id}
                    />
                </div>
            )}
        </FormSection>
    );
}
