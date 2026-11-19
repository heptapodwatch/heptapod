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
import { ReplicatorField } from '@/components/replicator-field';
import { SchemaFields, schemaFieldViews } from '@/components/schema-fields';
import { SheetForm } from '@/components/sheet-form';
import { TimezoneCombobox } from '@/components/timezone-combobox';
import { Button } from '@/components/ui/button';
import { Combobox, MultiCombobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Sheet } from '@/components/ui/sheet';
import { emptySelectValue } from '@/components/ui/select';
import { HttpValidationError, useHttpRequest } from '@/hooks/use-http-request';
import { store, update } from '@/routes/notification-channels';
import type { FieldValue } from '@/types/monitoring';

type Draft = {
    channel: string;
    label: string;
    locale: 'en' | 'de';
    /** Null follows the recipient's zone. */
    timezone: string | null;
    scope_client_id: number | null;
    check_types: string[] | null;
    config: Record<string, FieldValue>;
};

/**
 * Add a channel, or edit one's label, language, timezone, team scope and transport settings. Secrets are never sent back to the
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
        timezone: channel?.timezone ?? null,
        scope_client_id: channel?.scope_client_id ?? null,
        check_types: channel?.check_types ?? null,
        config: { ...channel?.config },
    });
    const [failure, setFailure] = useState('');
    const type = page.channel_types.find(
        (item) => item.key === form.data.channel,
    );
    const account = channel?.account_mail === true;
    if (!type && !account)
        throw new Error(`Channel type ${form.data.channel} is not installed.`);
    const webhook = type?.key === 'webhook';
    const settingsFields = type
        ? schemaFieldViews(type.config_schema).filter(
              (field) => !webhook || field.name !== 'headers',
          )
        : [];
    const headers = form.data.config.headers;
    const headerRows =
        webhook && Array.isArray(headers)
            ? headers.map((row) => {
                  if (typeof row === 'string' || typeof row.name !== 'string')
                      throw new Error('Expected webhook header rows.');
                  return { name: row.name, value: row.value ?? '********' };
              })
            : [];
    // Schema validation reports `values.<field>`; a kept secret that must be entered again reports `config.<field>`.
    const serverErrors = new Map(Object.entries(form.errors));
    const fieldErrors = Object.fromEntries(
        [...serverErrors].flatMap(([key, message]) => {
            const field = /^(?:values|config)\.(\w+)/.exec(key)?.[1];
            return field === undefined ? [] : [[field, String(message)]];
        }),
    );
    const checkTypes = form.data.check_types;
    const checkTypesError =
        form.errors.check_types ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('check_types.'),
        )?.[1];
    const checkTypesField = `${id}-check-types-${checkTypes === null ? 'mode' : 'selection'}`;
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
        check_types: data.check_types,
        timezone: data.timezone,
        ...(account ? {} : { label: data.label, config: data.config }),
        ...(team
            ? { locale: data.locale, scope_client_id: data.scope_client_id }
            : {}),
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
                    account
                        ? 'recipient_settings.check_types.account_description'
                        : channel !== null
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
                        <Label htmlFor={`${id}-type`}>{t('Type')}</Label>
                        <Combobox
                            id={`${id}-type`}
                            invalid={!!form.errors.channel}
                            describedBy={
                                fieldErrorProps(
                                    `${id}-type-group`,
                                    form.errors.channel,
                                )['aria-describedby']
                            }
                            value={form.data.channel}
                            onValueChange={(value) => {
                                form.setData((current) => ({
                                    ...current,
                                    channel: value,
                                    config: {},
                                }));
                                form.clearErrors();
                            }}
                            options={types.map((item) => ({
                                value: item.key,
                                label: t(item.label_key),
                                secondary: t(item.description_key),
                            }))}
                        />
                        <InputError
                            field={`${id}-type-group`}
                            message={form.errors.channel}
                        />
                    </div>
                )}
                {!account && (
                    <>
                        <div className="space-y-2">
                            <Label htmlFor={`${id}-label`}>{t('Name')}</Label>
                            <Input
                                id={`${id}-label`}
                                value={form.data.label}
                                placeholder={type && t(type.label_key)}
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
                    </>
                )}
                {webhook && (
                    <ReplicatorField
                        id={`${id}-headers`}
                        label={t('channels.field.headers')}
                        columns={[
                            {
                                key: 'name',
                                label: t('channels.field.header_name'),
                                placeholder: t('channels.field.header_name'),
                                mono: true,
                            },
                            {
                                key: 'value',
                                label: t('channels.field.header_value'),
                                placeholder: t('channels.field.header_value'),
                                type: 'password',
                            },
                        ]}
                        rows={headerRows}
                        onRowsChange={(rows) =>
                            form.setData('config', {
                                ...form.data.config,
                                headers: rows,
                            })
                        }
                        max={5}
                        addLabel={t('channels.headers.add')}
                        rowName={(index) =>
                            t('channels.headers.row', { index: index + 1 })
                        }
                        empty={t('channels.headers.empty')}
                        error={(index, key) =>
                            serverErrors.get(
                                `values.headers.${index}.${key}`,
                            ) ??
                            serverErrors.get(`config.headers.${index}.${key}`)
                        }
                        message={serverErrors.get('values.headers')}
                    />
                )}
                {team && (
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
                                form.setData('locale', value as Draft['locale'])
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
                )}
                <div className="space-y-2">
                    <Label htmlFor={`${id}-timezone`}>{t('Timezone')}</Label>
                    <TimezoneCombobox
                        id={`${id}-timezone`}
                        timezones={page.timezones}
                        value={form.data.timezone}
                        onValueChange={(value: string | null) =>
                            form.setData('timezone', value)
                        }
                        defaultZone={{
                            zone: page.schedule.timezone,
                            source:
                                page.recipient_kind === 'team'
                                    ? t('timezone.source.instance')
                                    : page.recipient_kind === 'user'
                                      ? t('timezone.source.profile')
                                      : t('timezone.source.client', {
                                            name: page.recipient_name,
                                        }),
                        }}
                        invalid={!!form.errors.timezone}
                        describedBy={
                            fieldErrorProps(
                                `${id}-timezone`,
                                form.errors.timezone,
                            )['aria-describedby']
                        }
                    />
                    <InputError
                        field={`${id}-timezone`}
                        message={form.errors.timezone}
                    />
                </div>
                {team && (
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
                                    label: t('recipient_settings.scope.all'),
                                },
                                ...clientComboboxOptions(page.clients),
                            ]}
                        />
                        <InputError
                            field={`${id}-scope`}
                            message={form.errors.scope_client_id}
                        />
                    </div>
                )}
                <div className="space-y-2">
                    <Label id={`${id}-check-types`}>
                        {t('recipient_settings.check_types.label')}
                    </Label>
                    <RadioGroup
                        id={`${id}-check-types-mode`}
                        aria-labelledby={`${id}-check-types`}
                        {...(checkTypes === null
                            ? fieldErrorProps(checkTypesField, checkTypesError)
                            : {})}
                        value={checkTypes === null ? 'all' : 'selected'}
                        onValueChange={(value) => {
                            form.setData(
                                'check_types',
                                value === 'all'
                                    ? null
                                    : (channel?.check_types ?? []),
                            );
                            form.clearErrors('check_types');
                        }}
                    >
                        <RadioGroupItem value="all">
                            {t('recipient_settings.check_types.all')}
                        </RadioGroupItem>
                        <RadioGroupItem value="selected">
                            {t('recipient_settings.check_types.selected')}
                        </RadioGroupItem>
                    </RadioGroup>
                    {checkTypes !== null && (
                        <MultiCombobox
                            id={checkTypesField}
                            ariaLabel={t(
                                'recipient_settings.check_types.select',
                            )}
                            placeholder={t(
                                'recipient_settings.check_types.select',
                            )}
                            options={page.check_types.map(
                                ({ value, label_key }) => ({
                                    value,
                                    label: t(label_key),
                                }),
                            )}
                            values={checkTypes}
                            invalid={!!checkTypesError}
                            describedBy={
                                fieldErrorProps(
                                    checkTypesField,
                                    checkTypesError,
                                )['aria-describedby']
                            }
                            onValuesChange={(values) => {
                                form.setData('check_types', values);
                                form.clearErrors('check_types');
                            }}
                        />
                    )}
                    <InputError
                        field={checkTypesField}
                        message={checkTypesError}
                    />
                </div>
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
