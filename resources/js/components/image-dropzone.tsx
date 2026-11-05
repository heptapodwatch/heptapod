import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormSecondaryAction } from '@/components/form-section';

const imageTypes = ['image/png', 'image/jpeg', 'image/webp'];
const maxBytes = 2 * 1024 * 1024;

export function ImageDropzone({
    value,
    onChange,
    onReset,
    error,
    disabled = false,
}: {
    value: File | null;
    onChange: (file: File) => void;
    onReset?: () => void;
    error?: string;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
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
            setLocalError(t('File too large (max 2 MB)'));
        } else if (!imageTypes.includes(file.type)) {
            setLocalError(t('Format not supported. Use PNG, JPEG or WebP.'));
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
                className="h-auto w-full flex-col gap-2 py-4 whitespace-normal"
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
                        alt={t('Avatar preview')}
                        className="size-16 rounded-md object-cover"
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
                {t('PNG, JPEG or WebP · Max 2 MB · Centered square crop')}
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
                    {t('Reset to automatic')}
                </FormSecondaryAction>
            )}
        </div>
    );
}
