/**
 * The email appearance fields of Settings → Email and their live preview. The preview is rendered by the real mail
 * templates on the server from the draft; logo images never leave the browser before Save: the preview carries only
 * their rendered size and the page swaps its own copy into the HTML.
 */
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { ImageDropzone } from '@/components/image-dropzone';
import { InfoTooltip } from '@/components/info-tooltip';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useHttpRequest } from '@/hooks/use-http-request';
import { preview as previewRoute } from '@/routes/settings/email';

/** A logo with the size Branded mail renders it at: 24 px high, or less when a wide logo hits the 200 px width cap. */
export type LogoDraft = {
    file: File;
    dataUrl: string;
    width: number;
    height: number;
};

export type AppearanceText = {
    sender_name: string;
    accent: string;
    footer: string;
};

type Field = keyof AppearanceText | 'logo' | 'logo_dark';

const logoHeight = 24;
const logoMaxWidth = 200;
const minContrast = 4.5;

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
    const [r = 0, g = 0, b = 0] = [1, 3, 5].map((i) => {
        const c = parseInt(hex.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Button text is white on the accent, and links use the accent on the white card: one ratio covers both (as the server). */
function contrastWithWhite(hex: string): number {
    return 1.05 / (luminance(hex) + 0.05);
}

/** The same hue, darkened until white text on it reaches 4.5:1. */
function readableShade(hex: string): string {
    for (let factor = 1; factor > 0; factor -= 0.02) {
        const shade =
            '#' +
            [1, 3, 5]
                .map((i) =>
                    Math.round(parseInt(hex.slice(i, i + 2), 16) * factor)
                        .toString(16)
                        .padStart(2, '0'),
                )
                .join('');
        if (contrastWithWhite(shade) >= minContrast) return shade;
    }
    return '#000000';
}

const validHex = (value: string) => /^#[0-9a-f]{6}$/i.test(value);

/** The same rendered size the server stores on Save. */
async function readLogo(file: File): Promise<LogoDraft> {
    const dataUrl = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });
    const image = new Image();
    image.src = dataUrl;
    await image.decode();
    const ratio = image.naturalWidth / image.naturalHeight;
    const width = Math.max(
        1,
        Math.min(logoMaxWidth, Math.round(logoHeight * ratio)),
    );
    return {
        file,
        dataUrl,
        width,
        height: Math.max(1, Math.min(logoHeight, Math.round(width / ratio))),
    };
}

/** The draft of one logo field; an unreadable image clears it (the dropzone has already said why). */
export function useLogoDraft(file: File | null): LogoDraft | null {
    const [logo, setLogo] = useState<LogoDraft | null>(null);
    useEffect(() => {
        let active = true;
        if (file === null) {
            setLogo(null);
            return;
        }
        readLogo(file).then(
            (read) => active && setLogo(read),
            () => active && setLogo(null),
        );
        return () => {
            active = false;
        };
    }, [file]);
    return logo;
}

/** A saved logo, read into a File so the dropzone and the preview treat it like a new upload. */
export function useSavedLogo(url: string | null): File | null {
    const [file, setFile] = useState<File | null>(null);
    useEffect(() => {
        let active = true;
        if (url === null) {
            setFile(null);
            return;
        }
        void fetch(url, { headers: { Accept: 'image/*' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Saved mail logo ${response.status}`);
                }
                return response.blob();
            })
            .then((blob) => {
                if (active) {
                    setFile(
                        new File([blob], url.split('/').pop() ?? 'logo', {
                            type: blob.type,
                        }),
                    );
                }
            });
        return () => {
            active = false;
        };
    }, [url]);
    return file;
}

/** The draft errors the page shows before the server answers; the server checks the same rules on Save. */
export function localErrors(
    text: AppearanceText,
    limits: { senderNameMax: number; footerMax: number },
    t: (key: string, values?: Record<string, unknown>) => string,
): Partial<Record<keyof AppearanceText, string>> {
    const errors: Partial<Record<keyof AppearanceText, string>> = {};
    if (text.sender_name.length > limits.senderNameMax) {
        errors.sender_name = t('mail_branding.sender.too_long', {
            max: limits.senderNameMax,
        });
    }
    if (text.accent !== '') {
        if (!validHex(text.accent)) {
            errors.accent = t('mail_branding.accent.format');
        } else if (contrastWithWhite(text.accent) < minContrast) {
            errors.accent = t('mail_branding.accent.unreadable', {
                ratio: (
                    Math.floor(contrastWithWhite(text.accent) * 10) / 10
                ).toFixed(1),
            });
        }
    }
    if (text.footer.length > limits.footerMax) {
        errors.footer = t('mail_branding.footer.too_long', {
            max: limits.footerMax,
        });
    } else if (/[<>]/.test(text.footer)) {
        errors.footer = t('mail_branding.footer.plain');
    }
    return errors;
}

export function AppearanceFields({
    text,
    logo,
    logoDark,
    errors,
    onText,
    onLogo,
    onRemoveLogo,
    onLogoDark,
    onRemoveLogoDark,
    fromName,
    fromAddress,
    footerMax,
    plain,
    disabled,
}: {
    text: AppearanceText;
    logo: LogoDraft | null;
    logoDark: LogoDraft | null;
    errors: Partial<Record<Field, string>>;
    onText: (field: keyof AppearanceText, value: string) => void;
    onLogo: (file: File) => void;
    onRemoveLogo: () => void;
    onLogoDark: (file: File) => void;
    onRemoveLogoDark: () => void;
    fromName: string;
    fromAddress: string;
    footerMax: number;
    plain: boolean;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const accentValid = validHex(text.accent);
    const unreadable =
        accentValid && contrastWithWhite(text.accent) < minContrast;
    return (
        <div className="space-y-5">
            {plain && (
                <Alert tone="info">
                    <AlertTitle>{t('mail_branding.plain_note')}</AlertTitle>
                </Alert>
            )}
            <div className="space-y-1.5">
                <Label htmlFor="mail-branding-sender">
                    {t('mail_branding.sender.label')}
                </Label>
                <Input
                    id="mail-branding-sender"
                    name="sender_name"
                    value={text.sender_name}
                    placeholder={fromName}
                    disabled={disabled}
                    onChange={(event) =>
                        onText('sender_name', event.target.value)
                    }
                    {...fieldErrorProps(
                        'mail-branding-sender',
                        errors.sender_name,
                        'mail-branding-sender-description',
                    )}
                />
                <p
                    id="mail-branding-sender-description"
                    className="text-xs text-muted-foreground"
                >
                    {t('mail_branding.sender.description', {
                        address: fromAddress,
                    })}
                </p>
                <InputError
                    field="mail-branding-sender"
                    message={errors.sender_name}
                />
            </div>
            <div className="space-y-1.5">
                <Label>{t('mail_branding.logo.label')}</Label>
                <p className="text-xs text-muted-foreground">
                    {t('mail_branding.logo.hint')}{' '}
                    <InfoTooltip>
                        {t('mail_branding.logo.hint_detail')}
                    </InfoTooltip>
                </p>
                <ImageDropzone
                    kind="logo"
                    value={logo?.file ?? null}
                    error={errors.logo}
                    disabled={disabled}
                    onChange={onLogo}
                    onReset={logo ? onRemoveLogo : undefined}
                />
                {!logo && (
                    <p className="text-xs text-muted-foreground">
                        {t('mail_branding.logo.empty')}
                    </p>
                )}
            </div>
            {logo && (
                <div className="space-y-1.5">
                    <Label>{t('mail_branding.logo_dark.label')}</Label>
                    <p className="text-xs text-muted-foreground">
                        {t('mail_branding.logo_dark.description')}{' '}
                        <InfoTooltip>
                            {t('mail_branding.logo_dark.detail')}
                        </InfoTooltip>
                    </p>
                    <ImageDropzone
                        kind="logoDark"
                        value={logoDark?.file ?? null}
                        error={errors.logo_dark}
                        disabled={disabled}
                        onChange={onLogoDark}
                        onReset={logoDark ? onRemoveLogoDark : undefined}
                    />
                </div>
            )}
            <div className="space-y-1.5">
                <Label htmlFor="mail-branding-accent">
                    {t('mail_branding.accent.label')}
                </Label>
                <div className="flex items-center gap-2">
                    <Input
                        type="color"
                        aria-label={t('mail_branding.accent.picker')}
                        value={accentValid ? text.accent : '#101418'}
                        disabled={disabled}
                        onChange={(event) =>
                            onText('accent', event.target.value)
                        }
                        className="w-10 shrink-0 cursor-pointer p-0.5 sm:w-8"
                    />
                    <Input
                        id="mail-branding-accent"
                        name="accent"
                        value={text.accent}
                        placeholder="#101418"
                        className="font-mono"
                        disabled={disabled}
                        onChange={(event) =>
                            onText('accent', event.target.value.trim())
                        }
                        {...fieldErrorProps(
                            'mail-branding-accent',
                            errors.accent,
                            'mail-branding-accent-description',
                        )}
                    />
                </div>
                <p
                    id="mail-branding-accent-description"
                    className="text-xs text-muted-foreground"
                >
                    {t('mail_branding.accent.description')}
                </p>
                <InputError
                    field="mail-branding-accent"
                    message={errors.accent}
                />
                {unreadable && (
                    <FormSecondaryAction
                        size="sm"
                        disabled={disabled}
                        onClick={() =>
                            onText('accent', readableShade(text.accent))
                        }
                    >
                        {t('mail_branding.accent.use_shade', {
                            colour: readableShade(text.accent),
                        })}
                    </FormSecondaryAction>
                )}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="mail-branding-footer">
                    {t('mail_branding.footer.label')}
                </Label>
                <Input
                    id="mail-branding-footer"
                    name="footer"
                    value={text.footer}
                    placeholder={t('mail_branding.footer.placeholder')}
                    disabled={disabled}
                    onChange={(event) => onText('footer', event.target.value)}
                    {...fieldErrorProps(
                        'mail-branding-footer',
                        errors.footer,
                        'mail-branding-footer-description',
                    )}
                />
                <p
                    id="mail-branding-footer-description"
                    className="text-xs text-muted-foreground"
                >
                    {t('mail_branding.footer.description', { max: footerMax })}
                </p>
                <InputError
                    field="mail-branding-footer"
                    message={errors.footer}
                />
            </div>
        </div>
    );
}

type Preview = { subject: string; html: string };

/** The draft as the real templates render it; fields the save would refuse keep their saved-default look here. */
export function AppearancePreview({
    design,
    text,
    invalid,
    logo,
    logoDark,
    fromName,
    fromAddress,
}: {
    design: string;
    text: AppearanceText;
    invalid: Partial<Record<keyof AppearanceText, string>>;
    logo: LogoDraft | null;
    logoDark: LogoDraft | null;
    fromName: string;
    fromAddress: string;
}) {
    const { t, i18n } = useTranslation();
    const [kind, setKind] = useState<'alert' | 'reset'>('alert');
    // The mail's colour scheme, forced on the iframe so the app theme never leaks into the preview.
    const [scheme, setScheme] = useState<'light' | 'dark'>('light');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [failed, setFailed] = useState(false);
    const request = useHttpRequest<Record<string, never>, Preview>();
    const applied = (field: keyof AppearanceText) =>
        invalid[field] === undefined && text[field].trim() !== ''
            ? text[field].trim()
            : null;
    const sender = applied('sender_name');
    const accent = applied('accent');
    const footer = applied('footer');
    useEffect(() => {
        let active = true;
        const url = previewRoute.url({
            query: {
                kind,
                design,
                sender_name: sender,
                accent,
                footer,
                logo_width: logo?.width ?? null,
                logo_height: logo?.height ?? null,
                logo_dark_width: logo ? (logoDark?.width ?? null) : null,
                logo_dark_height: logo ? (logoDark?.height ?? null) : null,
            },
        });
        const timer = window.setTimeout(() => {
            setFailed(false);
            request.get(url).then(
                (next) => active && setPreview(next),
                () => active && setFailed(true),
            );
        }, 250);
        return () => {
            active = false;
            window.clearTimeout(timer);
            request.cancel();
        };
    }, [
        kind,
        design,
        sender,
        accent,
        footer,
        logo,
        logoDark,
        i18n.language,
        request.get,
        request.cancel,
    ]);
    let html = preview?.html;
    if (html && logo) {
        html = html.replaceAll('cid:brand-logo@heptapod', logo.dataUrl);
    }
    if (html && logo && logoDark) {
        html = html.replaceAll(
            'cid:brand-logo-dark@heptapod',
            logoDark.dataUrl,
        );
    }
    return (
        <div className="min-w-0 space-y-2">
            <div className="flex flex-wrap gap-2">
                <SegmentedControl
                    label={t('mail_branding.preview.kind')}
                    value={kind}
                    onValueChange={(value) =>
                        setKind(value as 'alert' | 'reset')
                    }
                    options={[
                        {
                            value: 'alert',
                            label: t('mail_branding.preview.alert'),
                        },
                        {
                            value: 'reset',
                            label: t('mail_branding.preview.reset'),
                        },
                    ]}
                />
                <SegmentedControl
                    label={t('mail_branding.preview.scheme')}
                    value={scheme}
                    onValueChange={(value) =>
                        setScheme(value as 'light' | 'dark')
                    }
                    options={[
                        { value: 'light', label: t('Light') },
                        { value: 'dark', label: t('Dark') },
                    ]}
                />
            </div>
            {failed ? (
                <Alert tone="danger">
                    <AlertTitle>{t('mail_branding.preview.failed')}</AlertTitle>
                </Alert>
            ) : preview === null ? (
                <Spinner />
            ) : (
                <div className="overflow-hidden rounded-lg border">
                    <dl className="space-y-0.5 border-b bg-muted/40 px-3 py-2 text-xs">
                        <div className="flex min-w-0 gap-1">
                            <dt className="text-muted-foreground">
                                {t('mail_branding.preview.from')}
                            </dt>
                            <dd className="truncate">
                                {sender ?? fromName}{' '}
                                <span className="text-muted-foreground">
                                    &lt;{fromAddress}&gt;
                                </span>
                            </dd>
                        </div>
                        <div className="flex min-w-0 gap-1">
                            <dt className="text-muted-foreground">
                                {t('mail_branding.preview.subject')}
                            </dt>
                            <dd className="truncate font-medium">
                                {preview.subject}
                            </dd>
                        </div>
                    </dl>
                    <iframe
                        sandbox=""
                        srcDoc={html}
                        title={t('mail_branding.preview.title')}
                        className="h-[36rem] w-full"
                        style={{ colorScheme: scheme }}
                    />
                </div>
            )}
        </div>
    );
}
