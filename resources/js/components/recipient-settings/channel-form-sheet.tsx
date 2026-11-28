import { LanguageSelect } from '@/components/language-select';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { clientComboboxOptions } from '@/components/entity-combobox';
import InputError, { fieldErrorProps } from '@/components/input-error';
import type {
    Channel,
    SettingsPage,
} from '@/components/recipient-settings/model';
import { ReplicatorField } from '@/components/replicator-field';
import { SchemaFields, schemaFieldViews } from '@/components/schema-fields';
import { SheetForm } from '@/components/sheet-form';
import { TimezoneCombobox } from '@/components/timezone-combobox';
import { Combobox, MultiCombobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Sheet } from '@/components/ui/sheet';
import { emptySelectValue } from '@/components/ui/select';
import {
    HttpValidationError,
    httpErrorMessage,
    useHttpRequest,
    type HttpRequest,
} from '@/hooks/use-http-request';
import { store } from '@/routes/notification-channels';
import type { FieldValue } from '@/types/monitoring';
import type { Grid } from '@/components/recipient-settings/model';

type Draft = {
    channel: string;
    label: string;
    locale: string;
    /** Null follows the recipient's zone. */
    timezone: string | null;
    scope_client_id: number | null;
    check_types: string[] | null;
    config: Record<string, FieldValue>;
};

type Changes = Partial<Omit<Draft, 'channel'>>;
type ChannelRequest = Draft & {
    preferences?: Grid;
    mute_site_ids?: number[];
    unmute_site_ids?: number[];
};
type ChannelForm = HttpRequest<ChannelRequest, { id: number }>;

function initialDraft(
    page: SettingsPage,
    channel: Channel | null,
    language: string,
): Draft {
    const types = page.channel_types;
    const first = types[0];
    if (!first && !channel)
        throw new Error('No channel type can be added for this recipient.');
    return {
        channel: channel?.channel ?? first!.key,
        label: channel?.label ?? '',
        locale: channel?.locale ?? language,
        timezone: channel?.timezone ?? null,
        scope_client_id: channel?.scope_client_id ?? null,
        check_types: channel?.check_types ?? null,
        config: { ...channel?.config },
    };
}

export function useChannelForm(page: SettingsPage, channel: Channel | null) {
    const { i18n } = useTranslation();
    const [initial] = useState(() =>
        initialDraft(page, channel, i18n.language),
    );
    const form = useHttpRequest<ChannelRequest, { id: number }>(initial);
    const { data } = form;
    const changes: Changes = {};
    if (!channel?.account_mail) {
        if (data.label !== initial.label) changes.label = data.label;
        const config = Object.fromEntries(
            Object.entries(data.config).filter(
                ([key, value]) =>
                    JSON.stringify(value) !==
                    JSON.stringify(initial.config[key]),
            ),
        );
        if (Object.keys(config).length > 0) changes.config = config;
    }
    if (page.recipient_kind === 'team') {
        if (data.locale !== initial.locale) changes.locale = data.locale;
        if (data.scope_client_id !== initial.scope_client_id)
            changes.scope_client_id = data.scope_client_id;
    }
    if (data.timezone !== initial.timezone) changes.timezone = data.timezone;
    if (
        JSON.stringify(data.check_types) !== JSON.stringify(initial.check_types)
    )
        changes.check_types = data.check_types;

    const schema = page.channel_types.find(
        (type) => type.key === data.channel,
    )?.config_schema;
    const validConnection = Object.entries(schema?.fields ?? {}).every(
        ([key, field]) => {
            const value = data.config[key];
            if (
                field.type === 'secret' &&
                channel &&
                (value == null || value === '') &&
                channel.status !== 'needs_attention'
            )
                return true;
            if (value == null || value === '')
                return !field.rules.includes('required');
            if (typeof value !== 'string') return true;
            const text = value.trim();
            if (field.rules.includes('required') && text === '') return false;
            if (
                field.rules.includes('email') &&
                !/^[^\s@]+@[^\s@]+$/.test(text)
            )
                return false;
            const urlRule = field.rules.find((rule) => rule.startsWith('url:'));
            if (urlRule) {
                const url = URL.parse(text);
                return (
                    url !== null &&
                    urlRule
                        .slice(4)
                        .split(',')
                        .includes(url.protocol.slice(0, -1)) &&
                    (url.protocol === 'https:' ||
                        !field.rules.includes('webhook_scheme') ||
                        data.config.allow_insecure_http === true)
                );
            }
            if (
                data.channel === 'webhook' &&
                key === 'secret' &&
                text !== '********'
            )
                return text.length >= 32;
            return field.rules.every(
                (rule) =>
                    !rule.startsWith('size:') ||
                    text.length === Number(rule.slice(5)),
            );
        },
    );
    const valid =
        (channel?.account_mail ||
            (data.label.trim() !== '' &&
                data.label.length <= 255 &&
                validConnection)) &&
        (data.check_types === null || data.check_types.length > 0);
    const isConnectionError = (key: string) => {
        const [group, field] = key.split('.');
        if (!group || !channel) return false;
        if (group === 'values' || group === 'config') {
            return (
                !channel.account_mail &&
                !!field &&
                Object.hasOwn(schema?.fields ?? {}, field)
            );
        }
        return (
            group !== 'channel' &&
            Object.hasOwn(data, group) &&
            (group !== 'label' || !channel.account_mail) &&
            ((group !== 'locale' && group !== 'scope_client_id') ||
                page.recipient_kind === 'team')
        );
    };
    return { form, changes, valid, isConnectionError };
}

export function ChannelFormSheet({
    page,
    onSaved,
    onClose,
}: {
    page: SettingsPage;
    onSaved: (id: number) => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const { form, valid } = useChannelForm(page, null);
    const [failure, setFailure] = useState('');
    const team = page.recipient_kind === 'team';
    form.transform((data) => ({
        recipient_kind: page.recipient_kind,
        ...(page.recipient_kind === 'client'
            ? { client_id: page.recipient_id }
            : {}),
        channel: data.channel,
        label: data.label,
        config: data.config,
        check_types: data.check_types,
        timezone: data.timezone,
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
                title={t('Add channel')}
                description={t(
                    `recipient_settings.form.${page.recipient_kind}_description`,
                )}
                actions={{
                    kind: 'create',
                    onCancel: onClose,
                    valid,
                    busy: form.processing,
                    pendingLabel: form.processing ? t('Saving…') : undefined,
                }}
                aria-busy={form.processing}
                onSubmit={() => {
                    setFailure('');
                    void form
                        .post(store.url())
                        .then((result) => {
                            toast.success(
                                t('recipient_settings.channel_added'),
                            );
                            onSaved(result.id);
                        })
                        .catch((error: unknown) => {
                            if (!(error instanceof HttpValidationError))
                                setFailure(
                                    httpErrorMessage(
                                        error,
                                        t,
                                        'recipient_settings.save_failed',
                                    ),
                                );
                        });
                }}
            >
                <ChannelConnectionFields
                    page={page}
                    channel={null}
                    form={form}
                />
                <InputError message={failure} />
            </SheetForm>
        </Sheet>
    );
}

export function ChannelConnectionFields({
    page,
    channel,
    form,
}: {
    page: SettingsPage;
    channel: Channel | null;
    form: ChannelForm;
}) {
    const { t } = useTranslation();
    const id = useId();
    const types = page.channel_types;
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
            const [group, field] = key.split('.');
            return (group === 'values' || group === 'config') && field
                ? [[field, String(message)]]
                : [];
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
    return (
        <>
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
                        serverErrors.get(`values.headers.${index}.${key}`) ??
                        serverErrors.get(`config.headers.${index}.${key}`)
                    }
                    message={fieldErrors.headers}
                />
            )}
            {team && (
                <LanguageSelect
                    id={`${id}-locale`}
                    name="locale"
                    value={form.data.locale}
                    onValueChange={(value) => form.setData('locale', value)}
                    error={form.errors.locale}
                />
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
                        fieldErrorProps(`${id}-timezone`, form.errors.timezone)[
                            'aria-describedby'
                        ]
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
                {account && (
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'recipient_settings.check_types.account_description',
                        )}
                    </p>
                )}
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
                        ariaLabel={t('recipient_settings.check_types.select')}
                        placeholder={t('recipient_settings.check_types.select')}
                        options={page.check_types.map(
                            ({ value, label_key }) => ({
                                value,
                                label: t(label_key),
                            }),
                        )}
                        values={checkTypes}
                        invalid={!!checkTypesError}
                        describedBy={
                            fieldErrorProps(checkTypesField, checkTypesError)[
                                'aria-describedby'
                            ]
                        }
                        onValuesChange={(values) => {
                            form.setData('check_types', values);
                            form.clearErrors('check_types');
                        }}
                    />
                )}
                <InputError field={checkTypesField} message={checkTypesError} />
            </div>
        </>
    );
}
