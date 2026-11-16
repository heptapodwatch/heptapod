/**
 * Mail branding exploration (phase 1, iteration 2): the unsaved appearance fields of Settings → Email, with presets
 * switched by `?state=`. Nothing is stored; the preview is rendered by the real mail templates (dev-only endpoint).
 */
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { ImageDropzone } from '@/components/image-dropzone';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
import { TextLink } from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { preview as previewRoute } from '@/routes/dev/mail-branding';

export type ExploreState = 'empty' | 'filled' | 'invalid';
/** The rendered logo size in the mail header: 24 px high, or less when a wide logo hits the 200 px width cap. */
type Logo = { file: File; dataUrl: string; width: number; height: number };
export type BrandingDraft = {
    sender: string;
    logo: Logo | null;
    logoError?: string;
    accent: string;
    footer: string;
};

const states: ExploreState[] = ['empty', 'filled', 'invalid'];
const logoHeight = 24;
const logoMaxWidth = 200;
const footerMax = 160;
const minContrast = 4.5;

export function exploreState(): ExploreState {
    const state = new URLSearchParams(window.location.search).get(
        'state',
    ) as ExploreState | null;
    return state && states.includes(state) ? state : 'filled';
}

/** WCAG relative luminance of a #rrggbb colour. */
function luminance(hex: string): number {
    const [r = 0, g = 0, b = 0] = [1, 3, 5].map((i) => {
        const c = parseInt(hex.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Button text is white on the accent, and links use the accent on the white card: one ratio covers both. */
export function contrastWithWhite(hex: string): number {
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

async function readLogo(file: File): Promise<Logo> {
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
    const width = Math.min(logoMaxWidth, Math.round(logoHeight * ratio));
    return {
        file,
        dataUrl,
        width,
        height: Math.max(1, Math.min(logoHeight, Math.round(width / ratio))),
    };
}

/** A stand-in operator logo for the filled state, drawn at 2× the rendered height. */
async function sampleLogo(): Promise<Logo> {
    const canvas = document.createElement('canvas');
    canvas.width = 300;
    canvas.height = 48;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Canvas unavailable.');
    context.fillStyle = '#1d4ed8';
    context.beginPath();
    context.roundRect(0, 4, 40, 40, 9);
    context.fill();
    context.fillStyle = '#ffffff';
    context.font = '600 26px sans-serif';
    context.fillText('A', 11, 33);
    context.fillStyle = '#101418';
    context.font = '600 25px sans-serif';
    context.fillText('Acme Web Studio', 52, 33);
    const blob = await new Promise<Blob>((resolve, reject) =>
        canvas.toBlob(
            (value) => (value ? resolve(value) : reject(new Error('No blob.'))),
            'image/png',
        ),
    );
    return readLogo(new File([blob], 'acme-logo.png', { type: 'image/png' }));
}

const emptyDraft: BrandingDraft = {
    sender: '',
    logo: null,
    accent: '',
    footer: '',
};

const sameDraft = (a: BrandingDraft, b: BrandingDraft) =>
    a.sender === b.sender &&
    a.logo === b.logo &&
    a.logoError === b.logoError &&
    a.accent === b.accent &&
    a.footer === b.footer;

/**
 * The draft and its "saved" baseline, both seeded from the preset. `dirty` feeds the page bar; `markSaved` and
 * `discard` stand in for Save and Discard until phase 2 persists the values.
 */
export function useBrandingDraft(state: ExploreState) {
    const { t } = useTranslation();
    const [saved, setSaved] = useState<BrandingDraft>(emptyDraft);
    const [draft, setDraft] = useState<BrandingDraft>(emptyDraft);
    useEffect(() => {
        const seed = (preset: BrandingDraft) => {
            setSaved(preset);
            setDraft(preset);
        };
        if (state === 'empty') return;
        if (state === 'invalid') {
            seed({
                sender: 'Acme Monitoring',
                logo: null,
                logoError: t('mail_branding.logo.too_large'),
                accent: '#f5c400',
                footer: 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin',
            });
            return;
        }
        void sampleLogo().then((logo) =>
            seed({
                sender: 'Acme Monitoring',
                logo,
                accent: '#1d4ed8',
                footer: 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin',
            }),
        );
    }, [state, t]);
    return {
        draft,
        setDraft,
        dirty: !sameDraft(draft, saved),
        markSaved: () => setSaved(draft),
        discard: () => setDraft(saved),
    };
}

/** The accent that reaches the mail: only a valid, readable colour; anything else keeps the default. */
function appliedAccent(draft: BrandingDraft): string | null {
    return validHex(draft.accent) &&
        contrastWithWhite(draft.accent) >= minContrast
        ? draft.accent
        : null;
}

export function BrandingFields({
    draft,
    onChange,
    fromName,
    fromAddress,
    plain,
}: {
    draft: BrandingDraft;
    onChange: (draft: BrandingDraft) => void;
    fromName: string;
    fromAddress: string;
    plain: boolean;
}) {
    const { t } = useTranslation();
    const accentValid = validHex(draft.accent);
    const ratio = accentValid ? contrastWithWhite(draft.accent) : null;
    const accentError =
        draft.accent !== '' && !accentValid
            ? t('mail_branding.accent.format')
            : ratio !== null && ratio < minContrast
              ? t('mail_branding.accent.unreadable', {
                    ratio: ratio.toFixed(1),
                })
              : undefined;
    const footerError =
        draft.footer.length > footerMax
            ? t('mail_branding.footer.too_long', { max: footerMax })
            : undefined;
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
                    value={draft.sender}
                    placeholder={fromName}
                    maxLength={80}
                    onChange={(event) =>
                        onChange({ ...draft, sender: event.target.value })
                    }
                    aria-describedby="mail-branding-sender-description"
                />
                <p
                    id="mail-branding-sender-description"
                    className="text-xs text-muted-foreground"
                >
                    {t('mail_branding.sender.description', {
                        address: fromAddress,
                    })}
                </p>
            </div>
            <div className="space-y-1.5">
                <Label>{t('mail_branding.logo.label')}</Label>
                <ImageDropzone
                    kind="logo"
                    value={draft.logo?.file ?? null}
                    error={draft.logoError}
                    onChange={(file) =>
                        void readLogo(file).then((logo) =>
                            onChange({ ...draft, logo, logoError: undefined }),
                        )
                    }
                    onReset={
                        draft.logo || draft.logoError
                            ? () =>
                                  onChange({
                                      ...draft,
                                      logo: null,
                                      logoError: undefined,
                                  })
                            : undefined
                    }
                />
                {!draft.logo && !draft.logoError && (
                    <p className="text-xs text-muted-foreground">
                        {t('mail_branding.logo.empty')}
                    </p>
                )}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="mail-branding-accent">
                    {t('mail_branding.accent.label')}
                </Label>
                <div className="flex items-center gap-2">
                    <Input
                        type="color"
                        aria-label={t('mail_branding.accent.picker')}
                        value={accentValid ? draft.accent : '#101418'}
                        onChange={(event) =>
                            onChange({ ...draft, accent: event.target.value })
                        }
                        className="w-10 shrink-0 cursor-pointer p-0.5 sm:w-8"
                    />
                    <Input
                        id="mail-branding-accent"
                        value={draft.accent}
                        placeholder="#101418"
                        className="font-mono"
                        onChange={(event) =>
                            onChange({
                                ...draft,
                                accent: event.target.value.trim(),
                            })
                        }
                        {...fieldErrorProps(
                            'mail-branding-accent',
                            accentError,
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
                    message={accentError}
                />
                {accentValid && ratio !== null && ratio < minContrast && (
                    <FormSecondaryAction
                        size="sm"
                        onClick={() =>
                            onChange({
                                ...draft,
                                accent: readableShade(draft.accent),
                            })
                        }
                    >
                        {t('mail_branding.accent.use_shade', {
                            colour: readableShade(draft.accent),
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
                    value={draft.footer}
                    placeholder={t('mail_branding.footer.placeholder')}
                    onChange={(event) =>
                        onChange({ ...draft, footer: event.target.value })
                    }
                    {...fieldErrorProps(
                        'mail-branding-footer',
                        footerError,
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
                    message={footerError}
                />
            </div>
        </div>
    );
}

type Preview = { subject: string; html: string };

/** The real templates render the mail; the unsaved logo is swapped in locally, so it never leaves the browser. */
export function BrandingPreview({
    draft,
    design,
    fromName,
    fromAddress,
}: {
    draft: BrandingDraft;
    design: string;
    fromName: string;
    fromAddress: string;
}) {
    const { t, i18n } = useTranslation();
    const [kind, setKind] = useState<'alert' | 'reset'>('alert');
    const [preview, setPreview] = useState<Preview | null>(null);
    const accent = appliedAccent(draft);
    const footer =
        draft.footer.trim() !== '' && draft.footer.length <= footerMax
            ? draft.footer.trim()
            : null;
    const locale = i18n.language.startsWith('de') ? 'de' : 'en';
    useEffect(() => {
        const controller = new AbortController();
        const url = previewRoute.url({
            query: {
                kind,
                design,
                locale,
                accent,
                footer,
                logo_width: draft.logo?.width ?? null,
                logo_height: draft.logo?.height ?? null,
            },
        });
        const timer = window.setTimeout(() => {
            void fetch(url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json() as Promise<Preview>)
                .then(setPreview)
                .catch((error: unknown) => {
                    if (!controller.signal.aborted) throw error;
                });
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [kind, design, locale, accent, footer, draft.logo]);
    const html =
        preview && draft.logo
            ? preview.html.replaceAll(
                  'cid:brand-logo@heptapod',
                  draft.logo.dataUrl,
              )
            : preview?.html;
    const sender = draft.sender.trim() || fromName;
    return (
        <div className="min-w-0 space-y-2">
            <SegmentedControl
                label={t('mail_branding.preview.kind')}
                value={kind}
                onValueChange={(value) => setKind(value as 'alert' | 'reset')}
                options={[
                    { value: 'alert', label: t('mail_branding.preview.alert') },
                    { value: 'reset', label: t('mail_branding.preview.reset') },
                ]}
            />
            {preview === null ? (
                <Spinner />
            ) : (
                <div className="overflow-hidden rounded-lg border">
                    <dl className="space-y-0.5 border-b bg-muted/40 px-3 py-2 text-xs">
                        <div className="flex min-w-0 gap-1">
                            <dt className="text-muted-foreground">
                                {t('mail_branding.preview.from')}
                            </dt>
                            <dd className="truncate">
                                {sender}{' '}
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
                        className="h-[36rem] w-full bg-white"
                    />
                </div>
            )}
        </div>
    );
}

/** Dev-only switcher between the presets; shown in the PageHeader notices slot. */
export function ExploreSwitcher({ state }: { state: ExploreState }) {
    const link = (nextState: ExploreState) => `?state=${nextState}`;
    const item = (active: boolean, href: string, label: ReactNode) =>
        active ? (
            <strong key={href}>{label}</strong>
        ) : (
            <TextLink key={href} href={href}>
                {label}
            </TextLink>
        );
    return (
        <Alert tone="info">
            <AlertTitle>Mail branding exploration · not saved</AlertTitle>
            <AlertDescription>
                <p className="flex flex-wrap gap-x-3">
                    State: {states.map((s) => item(s === state, link(s), s))}
                </p>
            </AlertDescription>
        </Alert>
    );
}
