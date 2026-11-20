import { usePage } from '@inertiajs/react';
import { ClipboardCheck, Download } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Sheet } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/hooks/use-format';
import { httpResponseMessage } from '@/hooks/use-http-request';

type ExportFormat = Heptapod.Enums.ExportFormat;

const formats: { value: ExportFormat; label: string }[] = [
    { value: 'csv', label: 'export.format.csv' },
    { value: 'md', label: 'export.format.md' },
    { value: 'txt', label: 'export.format.txt' },
];

/** One "Include" choice: what every format of the export holds. */
export type ExportScope = {
    /** Remembered per page type, so it must not carry counts. */
    key: string;
    label: string;
    /** Rows in this scope, for the "first N of M" note; unknown for a stored file. */
    count?: number;
    /** A list scope with a known count: CSV refuses it past the row limit. A retained findings snapshot downloads in full. */
    csvLimited?: boolean;
    url: (format: ExportFormat) => string;
};

type Remembered = { format: ExportFormat; scope: string };

function remembered(kind: string): Remembered | null {
    const stored = window.localStorage.getItem(`export:${kind}`);
    return stored === null ? null : (JSON.parse(stored) as Remembered);
}

function filenameFromDisposition(
    header: string | null,
    fallback: string,
): string {
    if (!header) return fallback;
    const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (star?.[1]) {
        try {
            return decodeURIComponent(star[1]);
        } catch {
            // Fall through to plain filename token.
        }
    }
    const plain = /filename="?([^";]+)"?/i.exec(header);
    return plain?.[1]?.trim() || fallback;
}

class ExportFetchError extends Error {
    constructor(message: string) {
        super(message);
    }
}

/**
 * The one export control (heptapod-ui-rules Exports): an Export button that opens a sheet with Format, Include and
 * Cancel · Copy · Download. Every format comes from the server; Copy and Download both fetch the same endpoint so
 * server refusals (410 expired snapshot, 413 CSV row limit, 403/404, offline) stay inline inside the sheet.
 */
export function ExportButton({
    kind,
    scopes,
    variant = 'outline',
    size = 'sm',
}: {
    /** The page type whose last format and scope the sheet remembers. */
    kind: 'findings' | 'incidents' | 'client_messages';
    /** The first is the default; the Include choice shows only with two or more. */
    scopes: ExportScope[];
    /** Outline in card toolbars, ghost in list rows, primary only where export is the header's only action. */
    variant?: 'outline' | 'default' | 'ghost';
    /** Page headers use the default size, card toolbars and rows the small one. */
    size?: 'sm' | 'default';
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [format, setFormat] = useState<ExportFormat>('csv');
    const [scopeKey, setScopeKey] = useState(scopes[0]?.key ?? '');
    const [copied, setCopied] = useState(false);
    const [busy, setBusy] = useState(false);
    const [exportError, setExportError] = useState<string | null>(null);
    const [manualText, setManualText] = useState<string | null>(null);
    const rowLimit = usePage().props.exportRowLimit;
    const formatter = useFormat();
    const id = useId();
    const scope = scopes.find((item) => item.key === scopeKey) ?? scopes[0];
    useEffect(() => {
        if (!copied) return;
        const timer = window.setTimeout(() => setCopied(false), 2000);
        return () => window.clearTimeout(timer);
    }, [copied]);
    if (scope === undefined) return null;
    const overLimit = scope.count !== undefined && scope.count > rowLimit;
    const csvRefused = format === 'csv' && overLimit && scope.csvLimited;
    const openSheet = () => {
        const last = remembered(kind);
        if (last !== null) {
            setFormat(last.format);
            setScopeKey(
                scopes.some((item) => item.key === last.scope)
                    ? last.scope
                    : (scopes[0]?.key ?? ''),
            );
        }
        setCopied(false);
        setExportError(null);
        setOpen(true);
    };
    const remember = () =>
        window.localStorage.setItem(
            `export:${kind}`,
            JSON.stringify({ format, scope: scope.key } satisfies Remembered),
        );

    const requestExport = async (): Promise<Response> => {
        let response: Response;
        try {
            response = await fetch(scope.url(format), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
        } catch {
            throw new ExportFetchError(t('errors.network'));
        }
        if (!response.ok) {
            const payload: { message?: string; code?: string } | null =
                await response.json().catch(() => null);
            throw new ExportFetchError(
                httpResponseMessage(
                    response.status,
                    payload,
                    t,
                    'export.failed',
                ),
            );
        }
        return response;
    };

    const download = async () => {
        remember();
        setExportError(null);
        setBusy(true);
        try {
            const response = await requestExport();
            const blob = await response.blob();
            const filename = filenameFromDisposition(
                response.headers.get('content-disposition'),
                `export.${format}`,
            );
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = filename;
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(objectUrl);
            setOpen(false);
        } catch (error: unknown) {
            setExportError(
                error instanceof ExportFetchError
                    ? error.message
                    : t('export.failed'),
            );
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        remember();
        setExportError(null);
        let failureMessage: string | null = null;
        const text = requestExport()
            .then((response) => response.text())
            .catch((error: unknown) => {
                failureMessage =
                    error instanceof ExportFetchError
                        ? error.message
                        : t('export.failed');
                throw error;
            });
        try {
            if (!navigator.clipboard) throw new Error('No clipboard');
            // A promised ClipboardItem keeps the click's user activation (Safari) while the server answers.
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/plain': text.then(
                        (value) => new Blob([value], { type: 'text/plain' }),
                    ),
                }),
            ]);
            setCopied(true);
        } catch {
            const value = await text.catch(() => null);
            if (value === null) {
                setExportError(failureMessage ?? t('export.failed'));
                return;
            }
            setOpen(false);
            setManualText(value);
        }
    };

    return (
        <>
            <Button
                type="button"
                size={size}
                variant={variant}
                aria-haspopup="dialog"
                onClick={openSheet}
            >
                <Download aria-hidden />
                {t('Export')}
            </Button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetForm
                    title={t('Export')}
                    description={t('export.sheet_description')}
                    onSubmit={(event) => {
                        event.preventDefault();
                        void download();
                    }}
                    footer={
                        <>
                            <FormSecondaryAction
                                disabled={busy}
                                onClick={() => setOpen(false)}
                            >
                                {t('Cancel')}
                            </FormSecondaryAction>
                            <FormSecondaryAction
                                disabled={format === 'csv' || busy}
                                onClick={() => void copy()}
                            >
                                {copied ? (
                                    <>
                                        <ClipboardCheck aria-hidden />
                                        {t('export.copied')}
                                    </>
                                ) : (
                                    t('Copy')
                                )}
                            </FormSecondaryAction>
                            <Button type="submit" disabled={csvRefused || busy}>
                                {t('export.download')}
                            </Button>
                        </>
                    }
                >
                    <div className="space-y-6">
                        <div className="space-y-2">
                            <Label id={`${id}-format`}>
                                {t('export.format.label')}
                            </Label>
                            <div>
                                <SegmentedControl
                                    label={t('export.format.label')}
                                    value={format}
                                    onValueChange={(next) => {
                                        setFormat(next as ExportFormat);
                                        setCopied(false);
                                        setExportError(null);
                                    }}
                                    options={formats.map((option) => ({
                                        value: option.value,
                                        label: t(option.label),
                                    }))}
                                />
                            </div>
                            {format === 'csv' && (
                                <p className="text-xs text-muted-foreground">
                                    {t('export.csv_no_copy')}
                                </p>
                            )}
                        </div>
                        {scopes.length > 1 && (
                            <div className="space-y-2">
                                <Label id={`${id}-scope`}>
                                    {t('export.include')}
                                </Label>
                                <RadioGroup
                                    aria-labelledby={`${id}-scope`}
                                    orientation="vertical"
                                    value={scope.key}
                                    onValueChange={(next) => {
                                        setScopeKey(next);
                                        setCopied(false);
                                        setExportError(null);
                                    }}
                                >
                                    {scopes.map((option) => (
                                        <RadioGroupItem
                                            key={option.key}
                                            value={option.key}
                                        >
                                            {option.label}
                                        </RadioGroupItem>
                                    ))}
                                </RadioGroup>
                            </div>
                        )}
                        {overLimit && (format !== 'csv' || csvRefused) && (
                            <p className="text-xs text-muted-foreground">
                                {csvRefused
                                    ? t('export.csv_too_large', {
                                          max: formatter.number(rowLimit),
                                      })
                                    : t('export.capped', {
                                          shown: formatter.number(rowLimit),
                                          total: formatter.number(
                                              scope.count ?? 0,
                                          ),
                                      })}
                            </p>
                        )}
                        <InputError message={exportError} />
                    </div>
                </SheetForm>
            </Sheet>
            <ManualCopyDialog
                text={manualText}
                onClose={() => setManualText(null)}
            />
        </>
    );
}

/** Without a clipboard (an insecure origin, a refused permission) the text is shown preselected to copy by hand. */
function ManualCopyDialog({
    text,
    onClose,
}: {
    text: string | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const textarea = useRef<HTMLTextAreaElement>(null);
    return (
        <Dialog
            open={text !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    textarea.current?.focus();
                    textarea.current?.select();
                }}
            >
                <DialogHeader>
                    <DialogTitle>{t('export.manual_title')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Clipboard unavailable. Copy the selected text manually.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    ref={textarea}
                    readOnly
                    value={text ?? ''}
                    aria-label={t('export.manual_title')}
                    className="field-sizing-fixed h-72 font-mono text-xs"
                />
            </DialogContent>
        </Dialog>
    );
}
