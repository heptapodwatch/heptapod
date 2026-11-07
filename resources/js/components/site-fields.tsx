import { Combobox } from '@/components/ui/combobox';
import { ClientMark } from '@/components/client-mark';
import { useTranslation } from 'react-i18next';
import type { ComponentProps, ReactNode } from 'react';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { listRowPadding } from '@/components/ui/table';
import { cn } from '@/lib/utils';

type InputFieldProps = ComponentProps<typeof Input> & { error?: string };

export function SiteNameField({
    error,
    id = 'site-name',
    ...props
}: InputFieldProps) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{t('Name')}</Label>
            <Input
                id={id}
                maxLength={255}
                {...fieldErrorProps(id, error)}
                {...props}
            />
            <InputError field={id} message={error} />
        </div>
    );
}

export function SiteUrlField({
    error,
    help,
    id = 'site-url',
    ...props
}: InputFieldProps & { help?: string }) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{t('URL')}</Label>
            <Input
                id={id}
                {...fieldErrorProps(id, error, help && `${id}-help`)}
                {...props}
            />
            {help && (
                <p id={`${id}-help`} className="text-xs text-muted-foreground">
                    {help}
                </p>
            )}
            <InputError field={id} message={error} />
        </div>
    );
}

export function SiteTagsField({
    error,
    id = 'site-tags',
    ...props
}: InputFieldProps) {
    const { t } = useTranslation();
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{t('Tags')}</Label>
            <Input
                id={id}
                placeholder={t('Tags are separated by commas.')}
                {...fieldErrorProps(id, error, `${id}-help`)}
                {...props}
            />
            <p id={`${id}-help`} className="text-xs text-muted-foreground">
                {t('Tags are separated by commas.')}
            </p>
            <InputError field={id} message={error} />
        </div>
    );
}

type SiteClientFieldProps = { error?: string } & (
    | { client: Heptapod.Data.Ui.ClientData }
    | (Omit<
          ComponentProps<typeof Combobox>,
          'options' | 'value' | 'onValueChange' | 'create'
      > & {
          clients: Heptapod.Data.Ui.ClientData[];
          id?: string;
          value: string;
          onValueChange: (value: string) => void;
          onCreateClient?: (name: string) => void;
      })
);

export function SiteClientField({ error, ...props }: SiteClientFieldProps) {
    const { t } = useTranslation();
    if ('client' in props) {
        return (
            <div className="space-y-2">
                <p className="text-sm leading-none font-medium">
                    {t('Client')}
                </p>
                <p className="flex h-10 items-center gap-2 rounded-md border px-3 text-sm sm:h-8">
                    <ClientMark client={props.client} small />
                    {props.client.name}
                </p>
            </div>
        );
    }
    const {
        clients,
        id = 'site-client',
        onCreateClient,
        ...selectProps
    } = props;
    const option = (client: Heptapod.Data.Ui.ClientData) => ({
        value: String(client.id),
        label: client.name,
        mark: <ClientMark client={client} small />,
    });
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{t('Client')}</Label>
            {onCreateClient ? (
                <Combobox
                    id={id}
                    options={clients.map(option)}
                    value={selectProps.value}
                    onValueChange={selectProps.onValueChange}
                    disabled={selectProps.disabled}
                    invalid={!!error}
                    describedBy={fieldErrorProps(id, error)['aria-describedby']}
                    create={{
                        label: (name) =>
                            name
                                ? t('clients.create_inline', { name })
                                : t('New client'),
                        onCreate: onCreateClient,
                    }}
                />
            ) : (
                <Combobox
                    id={id}
                    options={clients.map(option)}
                    value={selectProps.value}
                    onValueChange={selectProps.onValueChange}
                    disabled={selectProps.disabled}
                    invalid={!!error}
                    describedBy={fieldErrorProps(id, error)['aria-describedby']}
                />
            )}
            <InputError field={id} message={error} />
        </div>
    );
}

/** One check toggle row: the whole row is the label, so the hit target is the row (40 px below sm), not only the switch. */
export function SiteCheckField({
    id,
    label,
    control,
    summary,
    action,
}: {
    id: string;
    label: ReactNode;
    control: ReactNode;
    /** Muted line below the label; wraps on narrow screens. Its id is `<id>-summary`, for the control's aria-describedby. */
    summary?: ReactNode;
    /** A row action beside the label; outside the label so it never toggles the switch. */
    action?: ReactNode;
}) {
    const field = (
        <label
            htmlFor={id}
            className={cn(
                'flex cursor-pointer items-center gap-3 font-[450] has-disabled:cursor-not-allowed has-disabled:opacity-50',
                action ? 'min-w-0 flex-1' : listRowPadding,
            )}
        >
            {control}
            <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2">
                {label}
                {summary && (
                    <span
                        id={id + '-summary'}
                        className="basis-full text-xs font-normal text-muted-foreground"
                    >
                        {summary}
                    </span>
                )}
            </span>
        </label>
    );
    return action ? (
        <div className={cn('flex items-center gap-3', listRowPadding)}>
            {field}
            <div className="shrink-0">{action}</div>
        </div>
    ) : (
        field
    );
}
