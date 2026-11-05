import type { ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/** The id of the InputError that belongs to the field `fieldId`. */
export function fieldErrorId(fieldId: string): string {
    return `${fieldId}-error`;
}

/**
 * The attributes that tie a field to its InputError: aria-invalid while there is a message, and the error's id after any
 * other descriptions (help text) in aria-describedby. Spread them on the control; pass the same `field` to InputError.
 */
export function fieldErrorProps(
    fieldId: string,
    message: string | null | undefined,
    ...describedBy: (string | null | undefined | false)[]
): {
    'aria-invalid': true | undefined;
    'aria-describedby': string | undefined;
} {
    const ids = [...describedBy, message ? fieldErrorId(fieldId) : null].filter(
        Boolean,
    );
    return {
        'aria-invalid': message ? true : undefined,
        'aria-describedby': ids.length > 0 ? ids.join(' ') : undefined,
    };
}

/**
 * The one inline error line for fields, forms and failed requests. A field error passes `field` (the control's id) and
 * the control spreads `fieldErrorProps` with the same id; a form or request error without a field passes neither.
 */
export default function InputError({
    message,
    field,
    className = '',
    ...props
}: Omit<ComponentProps<'p'>, 'children'> & {
    message?: string | null;
    field?: string;
}) {
    const { t } = useTranslation();
    return message ? (
        <p
            role="alert"
            id={field ? fieldErrorId(field) : undefined}
            {...props}
            className={cn('text-xs text-destructive', className)}
        >
            {t(message)}
        </p>
    ) : null;
}
