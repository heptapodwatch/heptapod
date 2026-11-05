import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import cronstrue from 'cronstrue';
import 'cronstrue/locales/de';
import { Plus, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import PasswordInput from '@/components/password-input';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { NumberField } from '@/components/ui/number-field';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type { FieldSchema, FieldValue } from '@/types/monitoring';

const propertyLabels: Record<string, string> = {
    name: 'Name',
    value: 'Value',
    path: 'Path',
    condition: 'Condition',
    operator: 'Operator',
};

export function schemaFieldViews(
    schema: Heptapod.Contracts.Schema.Schema,
    namespace = '',
    labels: Record<string, string> = {},
): FieldSchema[] {
    return Object.entries(schema.fields).map(([name, field]) => {
        const common = {
            name: namespace + name,
            label: labels[name] ?? field.presentation?.labelKey ?? name,
            help: field.presentation?.helpKey ?? undefined,
            required: field.rules.includes('required'),
        };
        const choices = Object.entries(field.presentation?.options ?? {});
        const enumRule = (field.items ?? field).rules.find((rule) =>
            rule.startsWith('in:'),
        );
        const options = choices.length
            ? choices.map(([value, label]) => ({ value, label }))
            : enumRule
              ? enumRule
                    .slice(3)
                    .split(',')
                    .map((value) => ({ value, label: value }))
              : [];
        if (field.type === 'boolean') return { ...common, type: 'toggle' };
        if (field.type === 'integer' || field.type === 'numeric')
            return {
                ...common,
                type: 'number',
                defaultValue:
                    typeof field.default === 'number'
                        ? field.default
                        : undefined,
                unit: name.endsWith('_in_days')
                    ? 'days'
                    : name.endsWith('_in_ms')
                      ? 'ms'
                      : name === 'timeout'
                        ? 'seconds'
                        : name.endsWith('_percentage')
                          ? '%'
                          : '',
            };
        if (field.type === 'secret') return { ...common, type: 'secret' };
        if (field.type === 'list') {
            if (options.length)
                return { ...common, type: 'multi-select', options };
            if (field.items?.type === 'object')
                return {
                    ...common,
                    type: 'object-list',
                    properties: Object.entries(field.items.properties).map(
                        ([name, property]) => {
                            const rule = property.rules.find((rule) =>
                                rule.startsWith('in:'),
                            );
                            if (!['string', 'secret'].includes(property.type))
                                throw new Error(
                                    'Unsupported list property type ' +
                                        property.type,
                                );
                            return {
                                name,
                                type: rule
                                    ? ('select' as const)
                                    : property.type === 'secret'
                                      ? ('secret' as const)
                                      : ('text' as const),
                                options: rule
                                    ? rule
                                          .slice(3)
                                          .split(',')
                                          .map((value) => ({
                                              value,
                                              label: value,
                                          }))
                                    : [],
                            };
                        },
                    ),
                };
            return { ...common, type: 'string-list' };
        }
        if (field.type !== 'string')
            throw new Error(
                'Unsupported configuration field type ' + field.type,
            );
        return options.length
            ? { ...common, type: 'select', options }
            : { ...common, type: 'text' };
    });
}

export function addSiteFieldViews(
    schema: Heptapod.Contracts.Schema.Schema,
    namespace = '',
    labels: Record<string, string> = {},
): FieldSchema[] {
    return schemaFieldViews(
        {
            fields: Object.fromEntries(
                Object.entries(schema.fields).filter(
                    ([, field]) =>
                        field.type !== 'object' ||
                        field.rules.includes('required'),
                ),
            ),
        },
        namespace,
        labels,
    );
}

export function cronPreview(expression: string, locale: string): string | null {
    if (expression.trim().split(/\s+/).length !== 5) return null;
    try {
        return cronstrue.toString(expression, {
            locale,
            throwExceptionOnParseError: true,
            use24HourTimeFormat: true,
        });
    } catch {
        // Invalid user-entered cron expressions are a normal inline validation state.
        return null;
    }
}

export function SchemaFields({
    schema,
    values,
    errors = {},
    onChange,
    columns = 2,
}: {
    schema: FieldSchema[];
    columns?: 1 | 2;
    values: Record<string, FieldValue>;
    errors?: Record<string, string>;
    onChange: (name: string, value: FieldValue) => void;
}) {
    const { t, i18n } = useTranslation();
    const prefix = useId();
    return (
        <div
            className={
                columns === 1 ? 'grid gap-6' : 'grid gap-6 sm:grid-cols-2'
            }
        >
            {schema.map((field) => {
                const id = `${prefix}-${field.name}`;
                const value = values[field.name];
                const text =
                    typeof value === 'string' || typeof value === 'number'
                        ? String(value)
                        : '';
                const preview =
                    field.type === 'cron' && text
                        ? cronPreview(text, i18n.language)
                        : null;
                // A typed cron expression that does not parse is this field's error until the server reports its own.
                const error =
                    errors[field.name] ??
                    (field.type === 'cron' && text && !preview
                        ? 'Enter a valid cron expression.'
                        : undefined);
                const common = {
                    id,
                    name: field.name,
                    disabled: field.disabled,
                    required: field.required,
                    ...fieldErrorProps(id, error, `${id}-help`),
                };
                let control;
                switch (field.type) {
                    case 'string-list':
                        control = (
                            <Textarea
                                {...common}
                                value={
                                    Array.isArray(value)
                                        ? value
                                              .map((item) => {
                                                  if (typeof item !== 'string')
                                                      throw new Error(
                                                          'String-list field requires string values',
                                                      );
                                                  return item;
                                              })
                                              .join('\n')
                                        : ''
                                }
                                onChange={(event) =>
                                    onChange(
                                        field.name,
                                        event.target.value
                                            .split(/\r?\n/)
                                            .filter(Boolean),
                                    )
                                }
                            />
                        );
                        break;
                    case 'toggle':
                        control = (
                            <Switch
                                {...common}
                                checked={value === true}
                                onCheckedChange={(checked) =>
                                    onChange(field.name, checked)
                                }
                            />
                        );
                        break;
                    case 'select':
                        control =
                            field.optionsSource !== 'data' &&
                            field.options.length >= 2 &&
                            field.options.length <= 4 ? (
                                <RadioGroup
                                    {...common}
                                    aria-labelledby={`${id}-label`}
                                    orientation={
                                        field.options.some(
                                            (option) =>
                                                t(option.label).length > 20,
                                        )
                                            ? 'vertical'
                                            : 'horizontal'
                                    }
                                    value={text}
                                    onValueChange={(selected) =>
                                        onChange(field.name, selected)
                                    }
                                >
                                    {field.options.map((option) => (
                                        <RadioGroupItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {t(option.label)}
                                        </RadioGroupItem>
                                    ))}
                                </RadioGroup>
                            ) : (
                                <Select
                                    value={text}
                                    disabled={field.disabled}
                                    required={field.required}
                                    name={field.name}
                                    onValueChange={(selected) =>
                                        onChange(field.name, selected)
                                    }
                                >
                                    {/* form-select: schema-enum Small fixed enums use the RadioGroup branch; data-backed or other sizes remain Select. */}
                                    <SelectTrigger
                                        {...common}
                                        className="w-full"
                                    >
                                        <SelectValue
                                            placeholder={t('Choose an option')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {field.options.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {t(option.label)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            );
                        break;
                    case 'multi-select': {
                        const selected = Array.isArray(value)
                            ? value.filter(
                                  (item): item is string =>
                                      typeof item === 'string',
                              )
                            : [];
                        control = (
                            <fieldset
                                disabled={field.disabled}
                                aria-describedby={common['aria-describedby']}
                                className="flex flex-wrap gap-4"
                            >
                                <legend className="sr-only">
                                    {t(field.label)}
                                </legend>
                                {field.options.map((option) => (
                                    <div
                                        key={option.value}
                                        className="flex min-h-10 items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`${id}-${option.value}`}
                                            checked={selected.includes(
                                                option.value,
                                            )}
                                            onCheckedChange={(checked) =>
                                                onChange(
                                                    field.name,
                                                    checked
                                                        ? [
                                                              ...selected,
                                                              option.value,
                                                          ]
                                                        : selected.filter(
                                                              (item) =>
                                                                  item !==
                                                                  option.value,
                                                          ),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`${id}-${option.value}`}
                                        >
                                            {t(option.label)}
                                        </Label>
                                    </div>
                                ))}
                            </fieldset>
                        );
                        break;
                    }
                    case 'object-list': {
                        const rows = Array.isArray(value)
                            ? value.filter(
                                  (item): item is Record<string, string> =>
                                      typeof item !== 'string',
                              )
                            : [];
                        control = (
                            <fieldset
                                disabled={field.disabled}
                                className="space-y-2"
                                aria-describedby={common['aria-describedby']}
                            >
                                <legend className="sr-only">
                                    {t(field.label)}
                                </legend>
                                {rows.map((row, index) => (
                                    <div
                                        key={index}
                                        className="flex flex-wrap items-end gap-2"
                                    >
                                        {field.properties.map((property) => (
                                            <div
                                                key={property.name}
                                                className="min-w-36 flex-1 space-y-1"
                                            >
                                                <Label
                                                    id={`${id}-${property.name}-${index}-label`}
                                                    htmlFor={
                                                        property.type ===
                                                            'select' &&
                                                        property.options
                                                            .length >= 2 &&
                                                        property.options
                                                            .length <= 4
                                                            ? undefined
                                                            : `${id}-${property.name}-${index}`
                                                    }
                                                >
                                                    {t(
                                                        propertyLabels[
                                                            property.name
                                                        ] ?? property.name,
                                                    )}
                                                    <span className="sr-only">
                                                        {index + 1}
                                                    </span>
                                                </Label>
                                                {property.type === 'select' &&
                                                property.options.length >= 2 &&
                                                property.options.length <= 4 ? (
                                                    <RadioGroup
                                                        id={`${id}-${property.name}-${index}`}
                                                        aria-labelledby={`${id}-${property.name}-${index}-label`}
                                                        orientation={
                                                            property.options.some(
                                                                (option) =>
                                                                    t(
                                                                        option.label,
                                                                    ).length >
                                                                    20,
                                                            )
                                                                ? 'vertical'
                                                                : 'horizontal'
                                                        }
                                                        disabled={
                                                            field.disabled
                                                        }
                                                        value={String(
                                                            row[
                                                                property.name
                                                            ] ?? '',
                                                        )}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            onChange(
                                                                field.name,
                                                                rows.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  [property.name]:
                                                                                      value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {property.options.map(
                                                            (option) => (
                                                                <RadioGroupItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {t(
                                                                        option.label,
                                                                    )}
                                                                </RadioGroupItem>
                                                            ),
                                                        )}
                                                    </RadioGroup>
                                                ) : property.type ===
                                                  'select' ? (
                                                    <Select
                                                        value={String(
                                                            row[
                                                                property.name
                                                            ] ?? '',
                                                        )}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            onChange(
                                                                field.name,
                                                                rows.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  [property.name]:
                                                                                      value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {/* form-select: schema-enum Nested fixed enums with two to four values use the RadioGroup branch. */}
                                                        <SelectTrigger
                                                            className="w-full"
                                                            id={`${id}-${property.name}-${index}`}
                                                            aria-label={`${t(propertyLabels[property.name] ?? property.name)} ${index + 1}`}
                                                        >
                                                            <SelectValue
                                                                placeholder={t(
                                                                    'Choose an option',
                                                                )}
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {property.options.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={String(
                                                                            option.value,
                                                                        )}
                                                                    >
                                                                        {t(
                                                                            option.label,
                                                                        )}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        id={`${id}-${property.name}-${index}`}
                                                        aria-label={`${t(propertyLabels[property.name] ?? property.name)} ${index + 1}`}
                                                        type={
                                                            property.type ===
                                                            'secret'
                                                                ? 'password'
                                                                : 'text'
                                                        }
                                                        value={
                                                            row[
                                                                property.name
                                                            ] ?? ''
                                                        }
                                                        className="min-w-0 font-mono"
                                                        onChange={(event) =>
                                                            onChange(
                                                                field.name,
                                                                rows.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  [property.name]:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                )}
                                            </div>
                                        ))}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="shrink-0"
                                            aria-label={`${t('Remove pair')} ${index + 1}`}
                                            onClick={() =>
                                                onChange(
                                                    field.name,
                                                    rows.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"

                                    onClick={() =>
                                        onChange(field.name, [
                                            ...rows,
                                            Object.fromEntries(
                                                field.properties.map(
                                                    (property) => [
                                                        property.name,
                                                        property.options[0]
                                                            ?.value ?? '',
                                                    ],
                                                ),
                                            ),
                                        ])
                                    }
                                >
                                    <Plus />
                                    {t('Add pair')}
                                </Button>
                            </fieldset>
                        );
                        break;
                    }
                    case 'secret':
                        control = (
                            <PasswordInput
                                {...common}
                                className="h-10"
                                value={text}
                                autoComplete="new-password"
                                onChange={(event) =>
                                    onChange(field.name, event.target.value)
                                }
                            />
                        );
                        break;
                    case 'number':
                    case 'duration':
                        control = (
                            <NumberField
                                id={common.id}
                                name={common.name}
                                disabled={common.disabled}
                                required={common.required}
                                invalid={common['aria-invalid']}
                                describedBy={common['aria-describedby']}
                                min={field.min}
                                max={field.max}
                                unit={field.unit ? t(field.unit) : undefined}
                                value={
                                    typeof value === 'number'
                                        ? value
                                        : text === ''
                                          ? null
                                          : text
                                }
                                placeholder={
                                    field.defaultValue === undefined
                                        ? undefined
                                        : String(field.defaultValue)
                                }
                                onValueChange={(next) =>
                                    onChange(field.name, next ?? '')
                                }
                            />
                        );
                        break;
                    case 'cron':
                    case 'text':
                        control = (
                            <Input
                                {...common}
                                type="text"
                                value={text}
                                className={
                                    field.type === 'cron' ? 'font-mono' : ''
                                }
                                onChange={(event) =>
                                    onChange(field.name, event.target.value)
                                }
                            />
                        );
                        break;
                }
                return (
                    <div
                        key={field.name}
                        className={
                            field.type === 'object-list' && columns === 2
                                ? 'space-y-2 sm:col-span-2'
                                : 'space-y-2'
                        }
                    >
                        {field.type === 'object-list' ||
                        field.type === 'multi-select' ? (
                            <p className="text-sm font-medium">
                                {t(field.label)}
                            </p>
                        ) : (
                            <Label
                                id={`${id}-label`}
                                htmlFor={
                                    field.type === 'select' &&
                                    field.optionsSource !== 'data' &&
                                    field.options.length >= 2 &&
                                    field.options.length <= 4
                                        ? undefined
                                        : id
                                }
                            >
                                {t(field.label)}
                                {field.required && <span aria-hidden> *</span>}
                            </Label>
                        )}
                        {control}
                        <div
                            id={`${id}-help`}
                            className="space-y-1 text-xs text-muted-foreground"
                        >
                            {field.help && !error && <p>{t(field.help)}</p>}
                            {field.defaultValue !== undefined && !error && (
                                <p>
                                    {t('Default: {{value}}', {
                                        value: field.defaultValue,
                                    })}
                                </p>
                            )}
                            {preview && <p aria-live="polite">{preview}</p>}
                        </div>
                        {error && <InputError field={id} message={error} />}
                    </div>
                );
            })}
        </div>
    );
}
