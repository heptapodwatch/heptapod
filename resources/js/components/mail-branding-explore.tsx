/**
 * Mail branding exploration (phase 1): three placements of the same unsaved fields, switched by `?explore=` on
 * Settings → General. Nothing is stored; the preview is rendered by the real mail templates (dev-only endpoint).
 */
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import { InfoTooltip } from '@/components/info-tooltip';
import { SectionHeading } from '@/components/section-heading';
import { ImageDropzone } from '@/components/image-dropzone';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
import { SheetForm } from '@/components/sheet-form';
import { TextLink } from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { preview as previewRoute } from '@/routes/dev/mail-branding';

export type ExploreVariant = 'inline' | 'section' | 'sheet';
type ExploreState = 'empty' | 'filled' | 'invalid';
type Logo = { file: File; dataUrl: string; width: number };
export type BrandingDraft = {
    sender: string;
    logo: Logo | null;
    logoError?: string;
    accent: string;
    footer: string;
};

const variants: ExploreVariant[] = ['inline', 'section', 'sheet'];
const states: ExploreState[] = ['empty', 'filled', 'invalid'];
const logoHeight = 24;
const logoMaxWidth = 200;
const footerMax = 160;
const minContrast = 4.5;

export function exploreParams(): {
    variant: ExploreVariant | null;
    state: ExploreState;
} {
    const params = new URLSearchParams(window.location.search);
    const variant = params.get('explore') as ExploreVariant | null;
    const state = params.get('state') as ExploreState | null;
    return {
        variant: variant && variants.includes(variant) ? variant : null,
        state: state && states.includes(state) ? state : 'filled',
    };
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
    const width = Math.min(
        logoMaxWidth,
        Math.round((logoHeight * image.naturalWidth) / image.naturalHeight),
    );
    return { file, dataUrl, width };
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

export function useBrandingDraft(state: ExploreState) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<BrandingDraft>({
        sender: '',
        logo: null,
        accent: '',
        footer: '',
    });
    useEffect(() => {
        if (state === 'empty') return;
        if (state === 'invalid') {
            setDraft({
                sender: 'Acme Monitoring',
                logo: null,
                logoError: t('mail_branding.logo.too_large'),
                accent: '#f5c400',
                footer: 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin',
            });
            return;
        }
        void sampleLogo().then((logo) =>
            setDraft({
                sender: 'Acme Monitoring',
                logo,
                accent: '#1d4ed8',
                footer: 'Acme Web Studio GmbH · Hauptstraße 1 · 10115 Berlin',
            }),
        );
    }, [state, t]);
    return [draft, setDraft] as const;
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
    compact = false,
}: {
    draft: BrandingDraft;
    design: string;
    fromName: string;
    fromAddress: string;
    /** A scaled thumbnail for a 16rem side panel. */
    compact?: boolean;
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
                    {compact ? (
                        <div className="h-72 overflow-hidden bg-white">
                            <iframe
                                sandbox=""
                                srcDoc={html}
                                title={t('mail_branding.preview.title')}
                                className="h-[40rem] w-[35rem] origin-top-left scale-[0.457]"
                            />
                        </div>
                    ) : (
                        <iframe
                            sandbox=""
                            srcDoc={html}
                            title={t('mail_branding.preview.title')}
                            className="h-[36rem] w-full bg-white"
                        />
                    )}
                </div>
            )}
        </div>
    );
}

/** Dev-only switcher between the variants and their states; shown in the PageHeader notices slot. */
export function ExploreSwitcher({
    variant,
    state,
}: {
    variant: ExploreVariant;
    state: ExploreState;
}) {
    const link = (next: ExploreVariant, nextState: ExploreState) =>
        `?explore=${next}&state=${nextState}`;
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
                    Variant:{' '}
                    {variants.map((v, index) =>
                        item(
                            v === variant,
                            link(v, state),
                            `${'ABC'[index]} · ${v}`,
                        ),
                    )}
                </p>
                <p className="flex flex-wrap gap-x-3">
                    State:{' '}
                    {states.map((s) => item(s === state, link(variant, s), s))}
                </p>
            </AlertDescription>
        </Alert>
    );
}

/** Variant A: the Mail section grows the fields, with a thumbnail preview in the 16rem side panel and a full preview sheet. */
export function InlineVariant(props: {
    draft: BrandingDraft;
    onChange: (draft: BrandingDraft) => void;
    design: string;
    fromName: string;
    fromAddress: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <BrandingFields {...props} plain={props.design === 'plain'} />
            <div className="min-w-0 space-y-2">
                <BrandingPreview {...props} compact />
                <FormSecondaryAction size="sm" onClick={() => setOpen(true)}>
                    {t('mail_branding.preview.open')}
                </FormSecondaryAction>
            </div>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetForm
                    title={t('mail_branding.preview.title')}
                    description={t('mail_branding.preview.description')}
                    onSubmit={(event) => event.preventDefault()}
                    footer={
                        <FormSecondaryAction onClick={() => setOpen(false)}>
                            {t('Close')}
                        </FormSecondaryAction>
                    }
                >
                    <BrandingPreview {...props} />
                </SheetForm>
            </Sheet>
        </div>
    );
}

/**
 * Variant B: a dedicated "Email appearance" section across the full content width (new pattern: no FormSection title
 * column), so the live preview beside the fields is wide enough to read at desktop size.
 */
export function SectionVariant(props: {
    draft: BrandingDraft;
    onChange: (draft: BrandingDraft) => void;
    design: string;
    fromName: string;
    fromAddress: string;
}) {
    const { t } = useTranslation();
    return (
        <section
            aria-labelledby="mail-branding-section"
            className="space-y-4 border-b py-6"
        >
            <div className="space-y-1">
                <SectionHeading id="mail-branding-section">
                    {t('mail_branding.section.title')}
                </SectionHeading>
                <p className="text-sm text-muted-foreground">
                    {t('mail_branding.section.description')}{' '}
                    <InfoTooltip>
                        {t('mail_branding.section.detail')}
                    </InfoTooltip>
                </p>
            </div>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:gap-12">
                <BrandingFields {...props} plain={props.design === 'plain'} />
                <BrandingPreview {...props} />
            </div>
        </section>
    );
}

/** Variant C: the Mail section shows a summary; "Change appearance…" opens a sheet with the fields and the preview. */
export function SheetVariant(props: {
    draft: BrandingDraft;
    onChange: (draft: BrandingDraft) => void;
    design: string;
    fromName: string;
    fromAddress: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [sheetDraft, setSheetDraft] = useState(props.draft);
    const accent = appliedAccent(props.draft);
    return (
        <div className="space-y-3">
            <dl className="grid gap-4 sm:grid-cols-2">
                <Summary label={t('mail_branding.sender.label')}>
                    {props.draft.sender.trim() || props.fromName}
                </Summary>
                <Summary label={t('mail_branding.logo.label')}>
                    {props.draft.logo ? (
                        <img
                            src={props.draft.logo.dataUrl}
                            alt={t('mail_branding.logo.preview')}
                            className="h-6 max-w-[200px] object-contain"
                        />
                    ) : (
                        t('mail_branding.logo.none')
                    )}
                </Summary>
                <Summary label={t('mail_branding.accent.label')}>
                    <span className="inline-flex items-center gap-2 font-mono">
                        <span
                            aria-hidden="true"
                            className="size-3.5 rounded-sm border"
                            style={{ background: accent ?? '#101418' }}
                        />
                        {accent ?? t('mail_branding.accent.default')}
                    </span>
                </Summary>
                <Summary label={t('mail_branding.footer.label')}>
                    {props.draft.footer.trim() ||
                        t('mail_branding.footer.none')}
                </Summary>
            </dl>
            <FormSecondaryAction
                size="sm"
                onClick={() => {
                    setSheetDraft(props.draft);
                    setOpen(true);
                }}
            >
                {t('mail_branding.sheet.open')}
            </FormSecondaryAction>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetForm
                    title={t('mail_branding.section.title')}
                    description={t('mail_branding.section.description')}
                    detail={t('mail_branding.section.detail')}
                    onSubmit={(event) => {
                        event.preventDefault();
                        props.onChange(sheetDraft);
                        setOpen(false);
                    }}
                    footer={
                        <>
                            <FormSecondaryAction onClick={() => setOpen(false)}>
                                {t('Cancel')}
                            </FormSecondaryAction>
                            <Button type="submit">{t('Save')}</Button>
                        </>
                    }
                >
                    <BrandingFields
                        {...props}
                        draft={sheetDraft}
                        onChange={setSheetDraft}
                        plain={props.design === 'plain'}
                    />
                    <BrandingPreview {...props} draft={sheetDraft} />
                </SheetForm>
            </Sheet>
        </div>
    );
}

function Summary({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-1.5">
            <dt className="text-cell font-medium">{label}</dt>
            <dd className="text-cell break-words text-muted-foreground">
                {children}
            </dd>
        </div>
    );
}
