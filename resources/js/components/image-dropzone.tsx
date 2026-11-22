import { useEffect, useId, useRef, useState, type CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormSecondaryAction } from '@/components/form-section';

/** Avatars are cropped to a square in the app; mail logos go into email, where WebP and SVG are unreliable. */
const kinds: Record<
    'avatar' | 'logo' | 'logoDark',
    {
        types: string[];
        maxBytes: number;
        tooLarge: string;
        unsupported: string;
        constraints: string;
        previewAlt: string;
        previewClass: string;
        /** Mail logos are previewed on the mail's own card colour (light or dark), whatever the app theme. */
        previewStyle?: CSSProperties;
        reset: string;
    }
> = {
    avatar: {
        types: ['image/png', 'image/jpeg', 'image/webp'],
        maxBytes: 2 * 1024 * 1024,
        tooLarge: 'File too large (max 2 MB)',
        unsupported: 'Format not supported. Use PNG, JPEG or WebP.',
        constraints: 'PNG, JPEG or WebP · Max 2 MB · Centered square crop',
        previewAlt: 'Avatar preview',
        previewClass: 'size-16 rounded-md object-cover',
        reset: 'Reset to automatic',
    },
    logo: {
        types: ['image/png', 'image/jpeg'],
        maxBytes: 512 * 1024,
        tooLarge: 'mail_branding.logo.too_large',
        unsupported: 'mail_branding.logo.unsupported',
        constraints: 'mail_branding.logo.constraints',
        previewAlt: 'mail_branding.logo.preview',
        previewClass: 'h-8 max-w-[216px] rounded-md object-contain p-1',
        previewStyle: { background: '#ffffff' },
        reset: 'mail_branding.logo.remove',
    },
    logoDark: {
        types: ['image/png', 'image/jpeg'],
        maxBytes: 512 * 1024,
        tooLarge: 'mail_branding.logo.too_large',
        unsupported: 'mail_branding.logo.unsupported',
        constraints: 'mail_branding.logo.constraints',
        previewAlt: 'mail_branding.logo_dark.preview',
        previewClass: 'h-8 max-w-[216px] rounded-md object-contain p-1',
        previewStyle: { background: '#13171b' },
        reset: 'mail_branding.logo_dark.remove',
    },
};

export function ImageDropzone({
    value,
    onChange,
    onReset,
    error,
    disabled = false,
    kind = 'avatar',
}: {
    value: File | null;
    onChange: (file: File) => void;
    onReset?: () => void;
    error?: string;
    disabled?: boolean;
    kind?: keyof typeof kinds;
}) {
    const { t } = useTranslation();
    const { types: imageTypes, maxBytes, previewStyle, ...copy } = kinds[kind];
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [localError, setLocalError] = useState<string>();
    useEffect(() => {
        if (!value) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(value);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [value]);
    const accept = (files: File[]) => {
        if (disabled) return;
        const file = files[0];
        if (files.length !== 1 || !file) {
            setLocalError(t('Choose one image.'));
        } else if (file.size > maxBytes) {
            setLocalError(t(copy.tooLarge));
        } else if (!imageTypes.includes(file.type)) {
            setLocalError(t(copy.unsupported));
        } else {
            setLocalError(undefined);
            onChange(file);
        }
    };
    return (
        <div className="space-y-2">
            <Input
                ref={input}
                id={id}
                type="file"
                accept={imageTypes.join(',')}
                className="sr-only"
                tabIndex={-1}
                disabled={disabled}
                aria-hidden="true"
                onChange={(event) => {
                    accept(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />
            <Button
                type="button"
                variant="outline"
                // A multi-line drop target is a field, not an action: the form-field radius, not the button pill.
                className="h-auto w-full flex-col gap-2 rounded-md py-4 leading-[inherit] whitespace-normal"
                disabled={disabled}
                {...fieldErrorProps(
                    id,
                    localError || error,
                    `${id}-constraints`,
                )}
                onClick={() => input.current?.click()}
                onDragOver={(event) => event.preventDefault()}
                onDrop={(event) => {
                    event.preventDefault();
                    accept(Array.from(event.dataTransfer.files));
                }}
                onPaste={(event) => {
                    const files = Array.from(event.clipboardData.files);
                    if (files.length > 0) {
                        event.preventDefault();
                        accept(files);
                    }
                }}
            >
                {preview && !error && (
                    <img
                        src={preview}
                        alt={t(copy.previewAlt)}
                        className={copy.previewClass}
                        style={previewStyle}
                        onError={() => {
                            setPreview(null);
                            setLocalError(t('Image could not be read.'));
                        }}
                    />
                )}
                {t('Drop an image, click to choose, or paste')}
            </Button>
            <p
                id={`${id}-constraints`}
                className="text-xs text-muted-foreground"
            >
                {t(copy.constraints)}
            </p>
            <div aria-live="polite">
                <InputError field={id} message={localError || error} />
            </div>
            {onReset && (
                <FormSecondaryAction
                    size="sm"
                    disabled={disabled}
                    onClick={() => {
                        setLocalError(undefined);
                        onReset();
                    }}
                >
                    {t(copy.reset)}
                </FormSecondaryAction>
            )}
        </div>
    );
}
