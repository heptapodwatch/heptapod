import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { clientComboboxOptions } from '@/components/entity-combobox';
import { FormSecondaryAction } from '@/components/form-section';
import InputError, { fieldErrorProps } from '@/components/input-error';
import type {
    Channel,
    SettingsPage,
} from '@/components/recipient-settings/model';
import { SchemaFields, schemaFieldViews } from '@/components/schema-fields';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { RadioCards } from '@/components/ui/radio-cards';
import { Sheet } from '@/components/ui/sheet';
import { emptySelectValue } from '@/components/ui/select';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { store, update } from '@/routes/notification-channels';
import type { FieldValue } from '@/types/monitoring';

type Draft = {
    channel: string;
    label: string;
    locale: 'en' | 'de';
    scope_client_id: number | null;
    config: Record<string, FieldValue>;
};

/**
 * Add a channel, or edit one's label, language, team scope and transport settings. Secrets are never sent back to the
 * browser: on edit an empty secret field keeps the stored value.
 */
export function ChannelFormSheet({
    page,
    channel,
    onSaved,
    onClose,
}: {
    page: SettingsPage;
    /** Null adds a new channel. */
    channel: Channel | null;
    onSaved: (id: number) => void;
    onClose: () => void;
}) {
    const { t, i18n } = useTranslation();
    const id = useId();
    const types = page.channel_types;
    const first = types[0];
    if (!first)
        throw new Error('No channel type can be added for this recipient.');
    const form = useHttpRequest<Draft, { id: number }>({
        channel: channel?.channel ?? first.key,
        label: channel?.label ?? '',
        locale:
            (channel?.locale as Draft['locale'] | null) ??
            (i18n.language === 'de' ? 'de' : 'en'),
        scope_client_id: channel?.scope_client_id ?? null,
        config: { ...channel?.config },
    });
    const [failure, setFailure] = useState('');
    const type = page.channel_types.find(
        (item) => item.key === form.data.channel,
    );
    if (!type)
        throw new Error(`Channel type ${form.data.channel} is not installed.`);
    const settingsFields = schemaFieldViews(type.config_schema);
    // Schema validation reports `values.<field>`; a kept secret that must be entered again reports `config.<field>`.
    const fieldErrors = Object.fromEntries(
        Object.entries(form.errors).flatMap(([key, message]) => {
            const field = /^(?:values|config)\.(\w+)/.exec(key)?.[1];
            return field === undefined ? [] : [[field, String(message)]];
        }),
    );
    const team = page.recipient_kind === 'team';
    form.transform((data) => ({
        ...(channel === null
            ? {
                  recipient_kind: page.recipient_kind,
                  ...(page.recipient_kind === 'client'
                      ? { client_id: page.recipient_id }
                      : {}),
                  channel: data.channel,
              }
            : {}),
        label: data.label,
        ...(team
            ? { locale: data.locale, scope_client_id: data.scope_client_id }
            : {}),
        ...(channel?.account_mail ? {} : { config: data.config }),
    }));
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t(channel === null ? 'Add channel' : 'Edit channel')}
                description={t(
                    channel !== null
                        ? 'recipient_settings.form.edit_description'
                        : `recipient_settings.form.${page.recipient_kind}_description`,
                )}
                aria-busy={form.processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (form.processing) return;
                    setFailure('');
                    const request =
                        channel === null
                            ? form.post(store.url())
                            : form.patch(update.url(channel.id));
                    void request
                        .then((result) => {
                            toast.success(
                                t(
                                    channel === null
                                        ? 'recipient_settings.channel_added'
                                        : 'recipient_settings.channel_saved',
                                ),
                            );
                            onSaved(result.id);
                        })
                        .catch((error: unknown) => {
                            if (!(error instanceof HttpValidationError))
                                setFailure(t('recipient_settings.save_failed'));
                        });
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button type="submit" disabled={form.processing}>
                            {t(
                                form.processing
                                    ? 'Saving…'
                                    : channel === null
                                      ? 'Add channel'
                                      : 'Save',
                            )}
                        </Button>
                    </>
                }
            >
                {channel === null && (
                    <div className="space-y-2">
                        <Label id={`${id}-type`}>{t('Type')}</Label>
                        <RadioCards
                            labelledBy={`${id}-type`}
                            invalid={!!form.errors.channel}
                            describedBy={
                                fieldErrorProps(
                                    `${id}-type-group`,
                                    form.errors.channel,
                                )['aria-describedby']
                            }
                            value={form.data.channel}
                            onChange={(value) => {
                                form.setData('channel', value);
                                form.setData('config', {});
                                form.clearErrors();
                            }}
                            options={types.map((item) => ({
                                value: item.key,
                                label: t(item.label_key),
                                description: t(item.description_key),
                            }))}
                        />
                        <InputError
                            field={`${id}-type-group`}
                            message={form.errors.channel}
                        />
                    </div>
                )}
                <div className="space-y-2">
                    <Label htmlFor={`${id}-label`}>{t('Name')}</Label>
                    <Input
                        id={`${id}-label`}
                        value={form.data.label}
                        placeholder={t(type.label_key)}
                        aria-invalid={!!form.errors.label}
                        aria-describedby={`${id}-label-error`}
                        onChange={(event) =>
                            form.setData('label', event.target.value)
                        }
                    />
                    <InputError
                        id={`${id}-label-error`}
                        message={form.errors.label}
                    />
                </div>
                {!channel?.account_mail && (
                    <SchemaFields
                        columns={settingsFields.length === 1 ? 1 : 2}
                        schema={settingsFields}
                        values={form.data.config}
                        errors={fieldErrors}
                        onChange={(name, value) =>
                            form.setData('config', {
                                ...form.data.config,
                                [name]: value,
                            })
                        }
                    />
                )}
                {team && (
                    <>
                        <div className="space-y-2">
                            <Label id={`${id}-locale`}>{t('Language')}</Label>
                            <RadioGroup
                                aria-labelledby={`${id}-locale`}
                                {...fieldErrorProps(
                                    `${id}-locale-group`,
                                    form.errors.locale,
                                )}
                                value={form.data.locale}
                                onValueChange={(value) =>
                                    form.setData(
                                        'locale',
                                        value as Draft['locale'],
                                    )
                                }
                            >
                                <RadioGroupItem value="en">
                                    {t('English')}
                                </RadioGroupItem>
                                <RadioGroupItem value="de">
                                    {t('German')}
                                </RadioGroupItem>
                            </RadioGroup>
                            <InputError
                                field={`${id}-locale-group`}
                                message={form.errors.locale}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${id}-scope`}>
                                {t('recipient_settings.scope.label')}
                            </Label>
                            <Combobox
                                id={`${id}-scope`}
                                invalid={!!form.errors.scope_client_id}
                                describedBy={
                                    fieldErrorProps(
                                        `${id}-scope`,
                                        form.errors.scope_client_id,
                                    )['aria-describedby']
                                }
                                value={
                                    form.data.scope_client_id === null
                                        ? emptySelectValue
                                        : String(form.data.scope_client_id)
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'scope_client_id',
                                        value === emptySelectValue
                                            ? null
                                            : Number(value),
                                    )
                                }
                                options={[
                                    {
                                        value: emptySelectValue,
                                        label: t(
                                            'recipient_settings.scope.all',
                                        ),
                                    },
                                    ...clientComboboxOptions(page.clients),
                                ]}
                            />
                            <InputError
                                field={`${id}-scope`}
                                message={form.errors.scope_client_id}
                            />
                        </div>
                    </>
                )}
                <InputError
                    message={
                        (channel !== null ? form.errors.channel : undefined) ??
                        failure
                    }
                />
            </SheetForm>
        </Sheet>
    );
}
