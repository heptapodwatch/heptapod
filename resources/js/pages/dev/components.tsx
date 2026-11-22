import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    ChevronRight,
    Copy,
    ExternalLink,
    Globe,
    Moon,
    Play,
    Plus,
    Search,
    Settings,
    ShieldCheck,
    Sun,
    Trash2,
    Wrench,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ActionNoteSlot } from '@/components/action-note-slot';
import { CardToolbarActions } from '@/components/card-toolbar-actions';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { EmptyState as SharedEmptyState } from '@/components/empty-state';
import { EntityLink, rowTargetClass } from '@/components/entity-link';
import { EvidenceBlock } from '@/components/evidence-block';
import {
    FilterBar,
    FilterCombobox,
    FilterSelectTrigger,
} from '@/components/filter-bar';
import {
    FormActions,
    FormActionsDock,
    FormSaveStatus,
    FormSecondaryAction,
    FormSection,
} from '@/components/form-section';
import { InfoTooltip } from '@/components/info-tooltip';
import InputError, { fieldErrorProps } from '@/components/input-error';
import { clientPagination, ListFooter } from '@/components/list-footer';
import {
    CheckStrip,
    ContentState,
    EventTimeline,
    StatusBadge,
    StatusDot,
    statusColor,
    UptimeBar,
} from '@/components/monitoring';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { PageHeaderAction } from '@/components/page-header-actions';
import PasswordInput from '@/components/password-input';
import { ReplicatorField } from '@/components/replicator-field';
import { RowActions } from '@/components/row-actions';
import {
    RunGlyph,
    RunLine,
    RunResultChip,
    RunStatus,
    YouMarker,
} from '@/components/run-family';
import { ScoreRing } from '@/components/score-ring';
import { SectionHeading } from '@/components/section-heading';
import { SectionTabs } from '@/components/section-tabs';
import { SegmentedControl } from '@/components/segmented-control';
import { SheetForm } from '@/components/sheet-form';
import { ShownOnceSecret } from '@/components/shown-once-secret';
import { SiteAvatar } from '@/components/site-avatar';
import { MetricTile, Sparkline } from '@/components/sparkline';
import { StatTile } from '@/components/stat-tile';
import { TextLink, TextLinkButton } from '@/components/text-link';
import { TruncatedText } from '@/components/truncated-text';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Avatar,
    AvatarBadge,
    AvatarFallback,
    AvatarGroup,
    AvatarGroupCount,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox, CheckboxField } from '@/components/ui/checkbox';
import {
    Combobox,
    MultiCombobox,
    type ComboboxOption,
} from '@/components/ui/combobox';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@/components/ui/command';
import { DateTimeField, TimeField } from '@/components/ui/date-time-field';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Disclosure } from '@/components/ui/disclosure';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSeparator,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import { Label } from '@/components/ui/label';
import {
    NumberField,
    type NumberFieldValue,
} from '@/components/ui/number-field';
import { RadioCards } from '@/components/ui/radio-cards';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    ListHeader,
    ListRow,
    rowEmphasisClass,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipDetails,
    TooltipPanel,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { WeekdayToggleGroup } from '@/components/ui/weekday-toggle-group';
import {
    bucketForState,
    clients,
    dashboardEvents,
    galleryChecks,
    gallerySites,
    overviewEvents,
    snapshotNow,
    states,
} from '@/fixtures/design';
import { useAppearance, type ResolvedAppearance } from '@/hooks/use-appearance';
import { setLocale, type Locale } from '@/lib/i18n';
import { statusLabels } from '@/lib/status';
import { cn } from '@/lib/utils';

const sampleSite: Heptapod.Data.Ui.SiteRowData = gallerySites[0]!;
const sampleChecks: Heptapod.Data.Ui.CheckStripData[] = galleryChecks;
const sampleClient: Heptapod.Data.Ui.ClientData = clients[0]!;
const allSiteStates: Heptapod.Enums.SiteStatus[] = [...states, 'unavailable'];

const gallerySections = [
    { id: 'foundations', labelKey: 'gallery.section.foundations' },
    { id: 'actions', labelKey: 'gallery.section.actions' },
    { id: 'inputs', labelKey: 'gallery.section.inputs' },
    { id: 'navigation', labelKey: 'gallery.section.navigation' },
    { id: 'feedback', labelKey: 'gallery.section.feedback' },
    { id: 'overlays', labelKey: 'gallery.section.overlays' },
    { id: 'layout', labelKey: 'gallery.section.layout' },
    { id: 'monitoring', labelKey: 'Monitoring primitives' },
] as const;

const colorTokenGroups = [
    {
        labelKey: 'gallery.foundations.surfaces',
        tokens: [
            'background',
            'sidebar',
            'card',
            'popover',
            'secondary',
            'muted',
            'accent',
            'foreground',
            'muted-foreground',
            'foreground-faint',
            'primary',
            'primary-foreground',
            'primary-soft',
            'destructive',
            'destructive-foreground',
        ],
    },
    {
        labelKey: 'gallery.foundations.borders',
        tokens: [
            'border',
            'border-soft',
            'border-strong',
            'input',
            'control-border',
            'ring',
            'sidebar-border',
        ],
    },
    {
        labelKey: 'gallery.foundations.interaction',
        tokens: [
            'item-highlight',
            'primary-hover',
            'primary-active',
            'destructive-hover',
            'destructive-active',
            'surface-hover',
            'surface-active',
            'ghost-hover',
            'ghost-active',
            'disabled',
            'disabled-foreground',
            'disabled-primary',
            'disabled-destructive',
        ],
    },
    {
        labelKey: 'gallery.foundations.status_tooltip',
        tokens: [
            'status-ok',
            'status-warn',
            'status-down',
            'status-info',
            'status-error',
            'status-pending',
            'status-paused',
            'status-disabled',
            'tooltip-surface',
            'tooltip-border',
            'tooltip-subject',
            'tooltip-muted',
        ],
    },
] as const;

const typeScaleTokens = [
    {
        token: 'text-page-title',
        spec: '26px / 550 · lh 1.15 · -0.025em',
        sample: 'Page title · Geist',
        className: 'text-page-title',
    },
    {
        token: 'text-headline',
        spec: '20px / 550 · lh 1.3 · -0.015em',
        sample: 'All 64 sites are OK.',
        className: 'text-headline',
    },
    {
        token: 'text-stat',
        spec: '20px · lh 1.25 · font-mono tabular-nums',
        sample: '99.98 % · 212 ms',
        className: 'font-mono text-stat tabular-nums',
    },
    {
        token: 'text-section',
        spec: '14px / 550 · lh 20px',
        sample: 'Section, card, sheet & dialog title',
        className: 'text-section',
    },
    {
        token: 'text-sm',
        spec: '13.5px · body text',
        sample: 'Body · Geist — Checks run from eu-central every 60 seconds.',
        className: 'text-sm',
    },
    {
        token: 'text-cell',
        spec: '13px · controls & table cells',
        sample: 'shop.acme.example · HTTP 200 · 182 ms',
        className: 'text-cell',
    },
    {
        token: 'text-xs',
        spec: '12px · meta & helper copy',
        sample: 'Last checked 12 s ago · 09:12:04',
        className: 'text-xs text-muted-foreground',
    },
    {
        token: 'text-label',
        spec: '11.5px / 450 · table headers & badges',
        sample: 'Status · Client · Uptime 30d',
        className: 'text-label font-label text-muted-foreground',
    },
    {
        token: 'text-micro',
        spec: '10px · compact markers',
        sample: 'You · pin marker',
        className: 'text-micro leading-none',
    },
    {
        token: 'text-mark',
        spec: '9px · avatar initials',
        sample: 'AC',
        className: 'font-mono text-mark font-semibold',
    },
] as const;

const radiusTokens = [
    { label: 'rounded-sm · 7px', className: 'h-10 w-24 rounded-sm' },
    { label: 'rounded-md · 7px', className: 'h-10 w-24 rounded-md' },
    {
        label: 'rounded-lg · 10px (--radius)',
        className: 'h-10 w-24 rounded-lg',
    },
    { label: 'rounded-full · pill', className: 'h-10 w-24 rounded-full' },
] as const;

const spacingTokens = [
    { label: '1 · 4px', widthClass: 'w-1' },
    { label: '1.5 · 6px', widthClass: 'w-1.5' },
    { label: '2 · 8px', widthClass: 'w-2' },
    { label: '3 · 12px', widthClass: 'w-3' },
    { label: '4 · 16px', widthClass: 'w-4' },
    { label: '5 · 20px', widthClass: 'w-5' },
    { label: '6 · 24px', widthClass: 'w-6' },
    { label: '8 · 32px', widthClass: 'w-8' },
    { label: '10 · 40px', widthClass: 'w-10' },
    { label: '12 · 48px', widthClass: 'w-12' },
] as const;

const motionTokens = [
    { token: '--motion-press', use: 'Control hover & border' },
    { token: '--motion-press-in', use: 'Active tone snap-in' },
    { token: '--motion-press-out', use: 'Tone press settle' },
    { token: '--motion-focus', use: '2px focus ring' },
    { token: '--motion-enter', use: 'Popover & dialog enter' },
    { token: '--motion-exit', use: 'Overlay exit' },
    { token: '--motion-sheet', use: 'Drawer slide' },
    { token: '--ease-out', use: 'Default curve (ease-out)' },
    { token: '--ease-in-out', use: 'Layout shift (ease-in-out)' },
    { token: '--ease-drawer', use: 'Sheet slide' },
] as const;

function readCssToken(token: string): string {
    if (typeof window === 'undefined') return '';
    return getComputedStyle(document.documentElement)
        .getPropertyValue(token)
        .trim();
}

const buttonVariantsList = [
    'default',
    'outline',
    'ghost',
    'destructive',
] as const;

const buttonSizesList = ['default', 'xs', 'icon', 'icon-xs'] as const;

const selectIntervalOptions = [
    { value: '60', labelKey: 'gallery.inputs.interval_1m' },
    { value: '300', labelKey: 'gallery.inputs.interval_5m' },
    { value: '900', labelKey: 'gallery.inputs.interval_15m' },
    { value: '3600', labelKey: 'gallery.inputs.interval_1h' },
    { value: '86400', labelKey: 'gallery.inputs.interval_1d' },
] as const;

const comboboxClientOptions: ComboboxOption[] = [
    {
        value: 'acme',
        label: 'Acme',
        secondary: 'Europe/London · 12 sites',
        group: 'Active',
    },
    {
        value: 'northwind',
        label: 'Northwind',
        secondary: 'Europe/Berlin · 8 sites',
        group: 'Active',
    },
    {
        value: 'contoso',
        label: 'Contoso',
        secondary: 'America/New_York · 5 sites',
        group: 'Active',
    },
    {
        value: 'internal',
        label: 'Internal',
        secondary: 'Europe/Berlin · 3 sites',
        group: 'Other',
    },
];

const alertToneExamples = [
    {
        tone: 'info',
        titleKey: 'gallery.feedback.alert_info_title',
        descKey: 'gallery.feedback.alert_info_desc',
        hasAction: true,
    },
    {
        tone: 'warning',
        titleKey: 'gallery.feedback.alert_warning_title',
        descKey: 'gallery.feedback.alert_warning_desc',
        hasAction: true,
    },
    {
        tone: 'danger',
        titleKey: 'gallery.feedback.alert_danger_title',
        descKey: 'gallery.feedback.alert_danger_desc',
        hasAction: false,
    },
] as const;

const runOutcomes = ['ok', 'warning', 'failed', 'check-error'] as const;

const sampleTableRows = [
    {
        id: 1,
        name: 'api.northwind.example',
        client: 'Northwind',
        state: 'down' as Heptapod.Enums.SiteStatus,
        summary: 'HTTP 503 · 3,500 ms',
        singleAction: false,
    },
    {
        id: 2,
        name: 'shop.acme.example',
        client: 'Acme',
        state: 'warning' as Heptapod.Enums.SiteStatus,
        summary: 'Certificate expires in 6 d',
        singleAction: false,
    },
    {
        id: 3,
        name: 'docs.contoso.example',
        client: 'Contoso',
        state: 'ok' as Heptapod.Enums.SiteStatus,
        summary: 'HTTP 200 · 182 ms',
        singleAction: true,
    },
] as const;

function GallerySection({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className="scroll-mt-32 space-y-6 border-t pt-8 first:border-t-0 first:pt-0"
        >
            <div className="space-y-1">
                <SectionHeading>{title}</SectionHeading>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

function SubGroup({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-3">
            <p className="text-label font-label text-muted-foreground">
                {title}
            </p>
            {children}
        </div>
    );
}

function Spec({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('min-w-0 space-y-1.5', className)}>
            <code className="block font-mono text-label break-words text-muted-foreground">
                {label}
            </code>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

function FoundationsSection() {
    const { t } = useTranslation();
    return (
        <GallerySection
            id="foundations"
            title={t('gallery.section.foundations')}
            description={t('gallery.section.foundations_desc')}
        >
            <div className="space-y-5">
                {colorTokenGroups.map((group) => (
                    <SubGroup key={group.labelKey} title={t(group.labelKey)}>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                            {group.tokens.map((token) => (
                                <div key={token} className="min-w-0 space-y-1">
                                    <div
                                        className="h-10 rounded-md border"
                                        style={{
                                            backgroundColor: `var(--${token})`,
                                        }}
                                    />
                                    <code className="block truncate font-mono text-label text-muted-foreground">
                                        --{token}
                                    </code>
                                </div>
                            ))}
                        </div>
                    </SubGroup>
                ))}
                <SubGroup title={t('gallery.foundations.charts')}>
                    <div className="flex flex-wrap gap-3">
                        {[1, 2, 3, 4, 5].map((index) => (
                            <div key={index} className="space-y-1">
                                <div
                                    className="h-6 w-20 rounded-sm border"
                                    role="img"
                                    aria-label={t('Chart series {{count}}', {
                                        count: index,
                                    })}
                                    style={{
                                        backgroundColor: `var(--chart-${index})`,
                                    }}
                                />
                                <code className="block font-mono text-label text-muted-foreground">
                                    --chart-{index}
                                </code>
                            </div>
                        ))}
                    </div>
                </SubGroup>
            </div>

            <SubGroup title={t('gallery.foundations.type_scale')}>
                <div className="divide-y rounded-lg border bg-card">
                    {typeScaleTokens.map((item) => (
                        <div
                            key={item.token}
                            className="grid gap-2 px-4 py-3 sm:grid-cols-[14rem_minmax(0,1fr)] sm:items-baseline"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <code className="block font-mono text-label text-foreground">
                                    {item.token}
                                </code>
                                <span className="block font-mono text-label text-muted-foreground">
                                    {item.spec}
                                </span>
                            </div>
                            <p className={cn('min-w-0', item.className)}>
                                {item.sample}
                            </p>
                        </div>
                    ))}
                </div>
            </SubGroup>

            <div className="grid gap-6 lg:grid-cols-2">
                <SubGroup title={t('gallery.foundations.radii')}>
                    <div className="grid grid-cols-2 gap-3 rounded-lg border bg-card p-4">
                        {radiusTokens.map((item) => (
                            <Spec key={item.label} label={item.label}>
                                <div
                                    className={cn(
                                        'border bg-muted',
                                        item.className,
                                    )}
                                />
                            </Spec>
                        ))}
                    </div>
                </SubGroup>

                <SubGroup title={t('gallery.foundations.spacing')}>
                    <div className="space-y-2 rounded-lg border bg-card p-4">
                        {spacingTokens.map((step) => (
                            <div
                                key={step.label}
                                className="flex items-center gap-3"
                            >
                                <code className="w-20 shrink-0 font-mono text-label text-muted-foreground">
                                    {step.label}
                                </code>
                                <div
                                    className={cn(
                                        'h-3 rounded-sm bg-primary',
                                        step.widthClass,
                                    )}
                                />
                            </div>
                        ))}
                    </div>
                </SubGroup>
            </div>

            <SubGroup title={t('gallery.foundations.motion')}>
                <div className="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3">
                    {motionTokens.map((item) => {
                        const value = readCssToken(item.token);
                        return (
                            <div
                                key={item.token}
                                className="min-w-0 space-y-0.5 rounded-md border border-border-soft bg-background p-2.5"
                            >
                                <code className="block truncate font-mono text-label text-foreground">
                                    {item.token}
                                </code>
                                {value && (
                                    <code className="block truncate font-mono text-label text-muted-foreground">
                                        {value}
                                    </code>
                                )}
                                <span className="block text-xs text-muted-foreground">
                                    {item.use}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <Spec label={t('gallery.foundations.motion_feel')}>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button>{t('Run check')}</Button>
                        <Button variant="outline">{t('Open incident')}</Button>
                        <Button variant="ghost">{t('Ghost')}</Button>
                        <Button variant="destructive">
                            {t('Delete site')}
                        </Button>
                    </div>
                </Spec>
            </SubGroup>
        </GallerySection>
    );
}

function ActionsSection() {
    const { t } = useTranslation();
    return (
        <GallerySection
            id="actions"
            title={t('gallery.section.actions')}
            description={t('gallery.section.actions_desc')}
        >
            <SubGroup title={t('gallery.actions.matrix')}>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {buttonVariantsList.map((variant) => (
                        <div
                            key={variant}
                            className="space-y-3 rounded-lg border bg-card p-4"
                        >
                            {buttonSizesList.map((size) => {
                                const isIcon =
                                    size === 'icon' || size === 'icon-xs';
                                return (
                                    <Spec
                                        key={`${variant}-${size}`}
                                        label={`Button variant="${variant}" size="${size}"`}
                                    >
                                        <Button
                                            variant={variant}
                                            size={size}
                                            aria-label={
                                                isIcon
                                                    ? `${variant} ${size}`
                                                    : undefined
                                            }
                                        >
                                            {isIcon ? (
                                                <Play />
                                            ) : variant === 'destructive' ? (
                                                t('Delete site')
                                            ) : variant === 'outline' ? (
                                                t('Run check')
                                            ) : variant === 'ghost' ? (
                                                t('Ghost')
                                            ) : (
                                                t('Open incident')
                                            )}
                                        </Button>
                                    </Spec>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </SubGroup>

            <SubGroup title={t('gallery.actions.states')}>
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
                    {buttonVariantsList.map((variant) => (
                        <div key={variant} className="space-y-3">
                            <Spec label={`Button variant="${variant}" + icon`}>
                                <Button variant={variant}>
                                    {variant === 'destructive' ? (
                                        <Trash2 />
                                    ) : (
                                        <Play />
                                    )}
                                    {variant === 'destructive'
                                        ? t('Delete site')
                                        : t('Run check')}
                                </Button>
                            </Spec>
                            <Spec label={`Button variant="${variant}" loading`}>
                                <Button variant={variant} disabled aria-busy>
                                    <Spinner aria-hidden />
                                    {t('Loading')}
                                </Button>
                            </Spec>
                            <Spec
                                label={`Button variant="${variant}" disabled`}
                            >
                                <div className="flex items-center gap-2">
                                    <Button variant={variant} disabled>
                                        {t('Disabled')}
                                    </Button>
                                    <Button
                                        variant={variant}
                                        size="icon"
                                        disabled
                                        aria-label={`${variant} disabled icon`}
                                    >
                                        <Play />
                                    </Button>
                                </div>
                            </Spec>
                        </div>
                    ))}
                </div>
            </SubGroup>

            <SubGroup title={t('gallery.actions.links')}>
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Spec label='TextLink variant="primary"'>
                        <TextLink href="/dev/components#foundations">
                            {t('Sites')}
                        </TextLink>
                    </Spec>
                    <Spec label='TextLink variant="secondary"'>
                        <TextLink
                            variant="secondary"
                            href="/dev/components#monitoring"
                        >
                            {t('Monitoring primitives')}
                        </TextLink>
                    </Spec>
                    <Spec label="TextLink external icon">
                        <TextLink
                            external
                            href="https://heptapod.watch"
                            icon={<ExternalLink className="size-3.5" />}
                        >
                            heptapod.watch
                        </TextLink>
                    </Spec>
                    <Spec label="TextLink url">
                        <TextLink external url href="https://shop.acme.example">
                            https://shop.acme.example
                        </TextLink>
                    </Spec>
                    <Spec label="TextLink truncate (max-w-44)">
                        <div className="max-w-44">
                            <TextLink href="/dev/components#actions" truncate>
                                {t('gallery.overlays.truncated_sample')}
                            </TextLink>
                        </div>
                    </Spec>
                    <Spec label="TextLinkButton primary & secondary">
                        <div className="flex flex-wrap items-center gap-4">
                            <TextLinkButton
                                variant="primary"
                                onClick={() =>
                                    toast(t('gallery.actions.trigger_toast'))
                                }
                            >
                                {t('Open incident')}
                            </TextLinkButton>
                            <TextLinkButton
                                variant="secondary"
                                onClick={() =>
                                    toast(t('gallery.actions.trigger_toast'))
                                }
                            >
                                {t('Copy')}
                            </TextLinkButton>
                        </div>
                    </Spec>
                    <Spec label="EntityLink href">
                        <EntityLink
                            name={sampleSite.name}
                            href="/dev/components#monitoring"
                            icon={
                                <SiteAvatar
                                    name={sampleSite.name}
                                    iconUrl={sampleSite.identity.icon_url}
                                    iconBacking={
                                        sampleSite.identity.icon_backing
                                    }
                                />
                            }
                        />
                    </Spec>
                    <Spec label="EntityLink (plain)">
                        <EntityLink
                            name="portal.northwind.example"
                            icon={
                                <SiteAvatar
                                    name="portal.northwind.example"
                                    iconUrl={null}
                                    iconBacking="none"
                                />
                            }
                        />
                    </Spec>
                    <Spec label="Kbd & KbdGroup">
                        <div className="flex items-center gap-3">
                            <Kbd>⌘K</Kbd>
                            <KbdGroup>
                                <Kbd>G</Kbd>
                                <Kbd>S</Kbd>
                            </KbdGroup>
                            <Kbd>Esc</Kbd>
                        </div>
                    </Spec>
                </div>
            </SubGroup>
        </GallerySection>
    );
}

function InputsSection() {
    const { t } = useTranslation();
    const invalidMsg = t('gallery.inputs.invalid_example');

    const [textRest, setTextRest] = useState('');
    const [textFilled, setTextFilled] = useState('https://shop.acme.example');
    const [textInvalid, setTextInvalid] = useState('not-a-url');

    const [searchRest, setSearchRest] = useState('');
    const [searchFilled, setSearchFilled] = useState('northwind');

    const [passwordRest, setPasswordRest] = useState('');
    const [passwordFilled, setPasswordFilled] = useState(
        'correct-horse-staple',
    );
    const [passwordInvalid, setPasswordInvalid] = useState('short');

    const [areaRest, setAreaRest] = useState('');
    const [areaFilled, setAreaFilled] = useState(
        t('gallery.inputs.notes_filled'),
    );
    const [areaInvalid, setAreaInvalid] = useState('');

    const [numRest, setNumRest] = useState<NumberFieldValue>(null);
    const [numFilled, setNumFilled] = useState<NumberFieldValue>(1500);
    const [numInvalid, setNumInvalid] = useState<NumberFieldValue>('abc');

    const [dtRest, setDtRest] = useState('');
    const [dtFilled, setDtFilled] = useState('2026-11-22T14:30');
    const [dtInvalid, setDtInvalid] = useState('');

    const [timeRest, setTimeRest] = useState('');
    const [timeFilled, setTimeFilled] = useState('09:30');
    const [timeInvalid, setTimeInvalid] = useState('');

    const [selectRest, setSelectRest] = useState('');
    const [selectFilled, setSelectFilled] = useState('300');
    const [selectInvalid, setSelectInvalid] = useState('');

    const [comboRest, setComboRest] = useState('');
    const [comboFilled, setComboFilled] = useState('acme');
    const [comboInvalid, setComboInvalid] = useState('');

    const [multiRest, setMultiRest] = useState<string[]>([]);
    const [multiFilled, setMultiFilled] = useState<string[]>([
        'acme',
        'northwind',
    ]);
    const [multiInvalid, setMultiInvalid] = useState<string[]>([]);

    const [checkRest, setCheckRest] = useState(false);
    const [checkFilled, setCheckFilled] = useState(true);
    const [checkInvalid, setCheckInvalid] = useState(false);

    const [switchRest, setSwitchRest] = useState(false);
    const [switchFilled, setSwitchFilled] = useState(true);
    const [switchInvalid, setSwitchInvalid] = useState(false);

    const [radioRest, setRadioRest] = useState('');
    const [radioFilled, setRadioFilled] = useState('confirm');
    const [radioInvalid, setRadioInvalid] = useState('');

    const [cardRest, setCardRest] = useState('');
    const [cardFilled, setCardFilled] = useState('balanced');
    const [cardInvalid, setCardInvalid] = useState('');

    const [otpRest, setOtpRest] = useState('');
    const [otpFilled, setOtpFilled] = useState('482910');
    const [otpInvalid, setOtpInvalid] = useState('48');

    const [headers, setHeaders] = useState([
        { name: 'X-Probe-Region', value: 'eu-central' },
        { name: 'Accept-Language', value: 'en-GB' },
    ]);

    const radioCardOptions = [
        {
            value: 'strict',
            label: t('gallery.inputs.card_strict'),
            description: t('gallery.inputs.card_strict_desc'),
        },
        {
            value: 'balanced',
            label: t('gallery.inputs.card_balanced'),
            description: t('gallery.inputs.card_balanced_desc'),
            badge: <Badge>{t('gallery.inputs.recommended')}</Badge>,
        },
        {
            value: 'custom',
            label: t('gallery.inputs.card_custom'),
            description: t('gallery.inputs.card_custom_desc'),
            disabled: true,
        },
    ];

    return (
        <GallerySection
            id="inputs"
            title={t('gallery.section.inputs')}
            description={t('gallery.section.inputs_desc')}
        >
            <SubGroup title="Input">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label="Input (rest)">
                        <Label htmlFor="g-input-rest" className="mb-1.5">
                            {t('gallery.inputs.state_rest')}
                        </Label>
                        <Input
                            id="g-input-rest"
                            placeholder="https://example.com"
                            value={textRest}
                            onChange={(e) => setTextRest(e.target.value)}
                        />
                    </Spec>
                    <Spec label='Input value="…"'>
                        <Label htmlFor="g-input-filled" className="mb-1.5">
                            {t('gallery.inputs.state_filled')}
                        </Label>
                        <Input
                            id="g-input-filled"
                            value={textFilled}
                            onChange={(e) => setTextFilled(e.target.value)}
                        />
                    </Spec>
                    <Spec label="Input aria-invalid + InputError">
                        <Label htmlFor="g-input-invalid" className="mb-1.5">
                            {t('gallery.inputs.state_invalid')}
                        </Label>
                        <Input
                            id="g-input-invalid"
                            value={textInvalid}
                            onChange={(e) => setTextInvalid(e.target.value)}
                            {...fieldErrorProps('g-input-invalid', invalidMsg)}
                        />
                        <InputError
                            field="g-input-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="Input disabled">
                        <Label htmlFor="g-input-disabled" className="mb-1.5">
                            {t('gallery.inputs.state_disabled')}
                        </Label>
                        <Input
                            id="g-input-disabled"
                            disabled
                            value="https://locked.acme.example"
                            readOnly
                        />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title='Input type="search" & PasswordInput'>
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label='Input type="search" (rest)'>
                        <Input
                            type="search"
                            aria-label={t('Filter sites…')}
                            placeholder={t('Filter sites…')}
                            value={searchRest}
                            onChange={(e) => setSearchRest(e.target.value)}
                        />
                    </Spec>
                    <Spec label='Input type="search" (filled)'>
                        <Input
                            type="search"
                            aria-label={t('Filter sites…')}
                            value={searchFilled}
                            onChange={(e) => setSearchFilled(e.target.value)}
                        />
                    </Spec>
                    <Spec label="PasswordInput (filled)">
                        <PasswordInput
                            aria-label={t('gallery.inputs.state_filled')}
                            value={passwordFilled}
                            onChange={(e) => setPasswordFilled(e.target.value)}
                        />
                    </Spec>
                    <Spec label="PasswordInput (rest / invalid / disabled)">
                        <div className="space-y-2">
                            <PasswordInput
                                aria-label={t('gallery.inputs.state_rest')}
                                placeholder="••••••••"
                                value={passwordRest}
                                onChange={(e) =>
                                    setPasswordRest(e.target.value)
                                }
                            />
                            <PasswordInput
                                id="g-pw-invalid"
                                aria-label={t('gallery.inputs.state_invalid')}
                                value={passwordInvalid}
                                onChange={(e) =>
                                    setPasswordInvalid(e.target.value)
                                }
                                {...fieldErrorProps('g-pw-invalid', invalidMsg)}
                            />
                            <InputError
                                field="g-pw-invalid"
                                message={invalidMsg}
                            />
                            <PasswordInput
                                aria-label={t('gallery.inputs.state_disabled')}
                                value="disabled-secret"
                                disabled
                                readOnly
                            />
                        </div>
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="Textarea">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label="Textarea (rest)">
                        <Textarea
                            aria-label={t('gallery.inputs.state_rest')}
                            placeholder={t('gallery.inputs.notes_placeholder')}
                            value={areaRest}
                            onChange={(e) => setAreaRest(e.target.value)}
                        />
                    </Spec>
                    <Spec label="Textarea (filled)">
                        <Textarea
                            aria-label={t('gallery.inputs.state_filled')}
                            value={areaFilled}
                            onChange={(e) => setAreaFilled(e.target.value)}
                        />
                    </Spec>
                    <Spec label="Textarea aria-invalid">
                        <Textarea
                            id="g-area-invalid"
                            aria-label={t('gallery.inputs.state_invalid')}
                            placeholder={t('gallery.inputs.notes_placeholder')}
                            value={areaInvalid}
                            onChange={(e) => setAreaInvalid(e.target.value)}
                            {...fieldErrorProps('g-area-invalid', invalidMsg)}
                        />
                        <InputError
                            field="g-area-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="Textarea disabled">
                        <Textarea
                            aria-label={t('gallery.inputs.state_disabled')}
                            disabled
                            value={t('gallery.inputs.notes_filled')}
                            readOnly
                        />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="NumberField">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label='NumberField unit="ms" (rest)'>
                        <NumberField
                            value={numRest}
                            onValueChange={setNumRest}
                            min={100}
                            max={10000}
                            step={100}
                            unit="ms"
                            placeholder="1500"
                        />
                    </Spec>
                    <Spec label='NumberField value={1500} unit="ms"'>
                        <NumberField
                            value={numFilled}
                            onValueChange={setNumFilled}
                            min={100}
                            max={10000}
                            step={100}
                            unit="ms"
                        />
                    </Spec>
                    <Spec label="NumberField invalid">
                        <NumberField
                            id="g-num-invalid"
                            value={numInvalid}
                            onValueChange={setNumInvalid}
                            unit="ms"
                            invalid
                            describedBy="g-num-invalid-error"
                        />
                        <InputError
                            field="g-num-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="NumberField disabled">
                        <NumberField
                            value={2000}
                            onValueChange={() => {}}
                            unit="ms"
                            disabled
                        />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="DateTimeField & TimeField">
                <div className="space-y-4 rounded-lg border bg-card p-4">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Spec label='DateTimeField timezone="Europe/Berlin" (rest)'>
                            <DateTimeField
                                value={dtRest}
                                onValueChange={setDtRest}
                                timezone="Europe/Berlin"
                            />
                        </Spec>
                        <Spec label="DateTimeField (filled)">
                            <DateTimeField
                                value={dtFilled}
                                onValueChange={setDtFilled}
                                timezone="Europe/Berlin"
                            />
                        </Spec>
                        <Spec label="DateTimeField invalid">
                            <DateTimeField
                                id="g-dt-invalid"
                                value={dtInvalid}
                                onValueChange={setDtInvalid}
                                timezone="Europe/Berlin"
                                invalid
                                describedBy="g-dt-invalid-error"
                            />
                            <InputError
                                field="g-dt-invalid"
                                message={invalidMsg}
                                className="mt-1"
                            />
                        </Spec>
                        <Spec label="DateTimeField disabled">
                            <DateTimeField
                                value="2026-11-22T14:30"
                                onValueChange={() => {}}
                                timezone="Europe/Berlin"
                                disabled
                            />
                        </Spec>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Spec label="TimeField (rest)">
                            <TimeField
                                aria-label={t('gallery.inputs.state_rest')}
                                value={timeRest}
                                onValueChange={setTimeRest}
                            />
                        </Spec>
                        <Spec label='TimeField value="09:30"'>
                            <TimeField
                                aria-label={t('gallery.inputs.state_filled')}
                                value={timeFilled}
                                onValueChange={setTimeFilled}
                            />
                        </Spec>
                        <Spec label="TimeField invalid">
                            <TimeField
                                id="g-time-invalid"
                                aria-label={t('gallery.inputs.state_invalid')}
                                value={timeInvalid}
                                onValueChange={setTimeInvalid}
                                invalid
                                describedBy="g-time-invalid-error"
                            />
                            <InputError
                                field="g-time-invalid"
                                message={invalidMsg}
                                className="mt-1"
                            />
                        </Spec>
                        <Spec label="TimeField disabled">
                            <TimeField
                                aria-label={t('gallery.inputs.state_disabled')}
                                value="09:30"
                                onValueChange={() => {}}
                                disabled
                            />
                        </Spec>
                    </div>
                </div>
            </SubGroup>

            <SubGroup title="Select">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label="Select (rest)">
                        <Select
                            value={selectRest}
                            onValueChange={setSelectRest}
                        >
                            <SelectTrigger
                                aria-label={t('gallery.inputs.state_rest')}
                                className="w-full"
                            >
                                <SelectValue
                                    placeholder={t(
                                        'gallery.inputs.select_placeholder',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {selectIntervalOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {t(opt.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Spec>
                    <Spec label='Select value="300"'>
                        <Select
                            value={selectFilled}
                            onValueChange={setSelectFilled}
                        >
                            <SelectTrigger
                                aria-label={t('gallery.inputs.state_filled')}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {selectIntervalOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {t(opt.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Spec>
                    <Spec label="Select aria-invalid">
                        <Select
                            value={selectInvalid}
                            onValueChange={setSelectInvalid}
                        >
                            <SelectTrigger
                                id="g-select-invalid"
                                aria-label={t('gallery.inputs.state_invalid')}
                                className="w-full"
                                {...fieldErrorProps(
                                    'g-select-invalid',
                                    invalidMsg,
                                )}
                            >
                                <SelectValue
                                    placeholder={t(
                                        'gallery.inputs.select_placeholder',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {selectIntervalOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {t(opt.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            field="g-select-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="Select disabled">
                        <Select value="300" disabled>
                            <SelectTrigger
                                aria-label={t('gallery.inputs.state_disabled')}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {selectIntervalOptions.map((opt) => (
                                    <SelectItem
                                        key={opt.value}
                                        value={opt.value}
                                    >
                                        {t(opt.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="Combobox & MultiCombobox">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label="Combobox (rest)">
                        <Combobox
                            options={comboboxClientOptions}
                            value={comboRest}
                            onValueChange={setComboRest}
                            ariaLabel={t('gallery.inputs.state_rest')}
                        />
                    </Spec>
                    <Spec label='Combobox value="acme"'>
                        <Combobox
                            options={comboboxClientOptions}
                            value={comboFilled}
                            onValueChange={setComboFilled}
                            ariaLabel={t('gallery.inputs.state_filled')}
                        />
                    </Spec>
                    <Spec label="Combobox invalid">
                        <Combobox
                            id="g-combo-invalid"
                            options={comboboxClientOptions}
                            value={comboInvalid}
                            onValueChange={setComboInvalid}
                            invalid
                            describedBy="g-combo-invalid-error"
                            ariaLabel={t('gallery.inputs.state_invalid')}
                        />
                        <InputError
                            field="g-combo-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="Combobox disabled">
                        <Combobox
                            options={comboboxClientOptions}
                            value="northwind"
                            onValueChange={() => {}}
                            disabled
                            ariaLabel={t('gallery.inputs.state_disabled')}
                        />
                    </Spec>
                    <Spec label="MultiCombobox (rest)">
                        <MultiCombobox
                            options={comboboxClientOptions}
                            values={multiRest}
                            onValuesChange={setMultiRest}
                            ariaLabel={t('gallery.inputs.state_rest')}
                        />
                    </Spec>
                    <Spec label="MultiCombobox (filled)">
                        <MultiCombobox
                            options={comboboxClientOptions}
                            values={multiFilled}
                            onValuesChange={setMultiFilled}
                            ariaLabel={t('gallery.inputs.state_filled')}
                        />
                    </Spec>
                    <Spec label="MultiCombobox invalid">
                        <MultiCombobox
                            id="g-multi-invalid"
                            options={comboboxClientOptions}
                            values={multiInvalid}
                            onValuesChange={setMultiInvalid}
                            invalid
                            describedBy="g-multi-invalid-error"
                            ariaLabel={t('gallery.inputs.state_invalid')}
                        />
                        <InputError
                            field="g-multi-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="MultiCombobox disabled">
                        <MultiCombobox
                            options={comboboxClientOptions}
                            values={['acme']}
                            onValuesChange={() => {}}
                            disabled
                            ariaLabel={t('gallery.inputs.state_disabled')}
                        />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="Checkbox, CheckboxField & Switch">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label="CheckboxField (rest)">
                        <div className="space-y-2">
                            <CheckboxField
                                checked={checkRest}
                                onCheckedChange={(v) =>
                                    setCheckRest(v === true)
                                }
                                description={t('gallery.inputs.checkbox_desc')}
                            >
                                {t('gallery.inputs.checkbox_label')}
                            </CheckboxField>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    aria-label="Checkbox rest"
                                    checked={checkRest}
                                    onCheckedChange={(v) =>
                                        setCheckRest(v === true)
                                    }
                                />
                                <code className="font-mono text-label text-muted-foreground">
                                    Checkbox
                                </code>
                            </div>
                        </div>
                    </Spec>
                    <Spec label="CheckboxField (filled)">
                        <div className="space-y-2">
                            <CheckboxField
                                checked={checkFilled}
                                onCheckedChange={(v) =>
                                    setCheckFilled(v === true)
                                }
                                description={t('gallery.inputs.checkbox_desc')}
                            >
                                {t('gallery.inputs.checkbox_label')}
                            </CheckboxField>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    aria-label="Checkbox checked"
                                    checked={checkFilled}
                                    onCheckedChange={(v) =>
                                        setCheckFilled(v === true)
                                    }
                                />
                                <code className="font-mono text-label text-muted-foreground">
                                    Checkbox checked
                                </code>
                            </div>
                        </div>
                    </Spec>
                    <Spec label="CheckboxField aria-invalid">
                        <CheckboxField
                            id="g-check-invalid"
                            checked={checkInvalid}
                            onCheckedChange={(v) => setCheckInvalid(v === true)}
                            {...fieldErrorProps('g-check-invalid', invalidMsg)}
                        >
                            {t('gallery.inputs.checkbox_label')}
                        </CheckboxField>
                        <InputError
                            field="g-check-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="CheckboxField disabled (off & on)">
                        <div className="space-y-1">
                            <CheckboxField disabled checked={false}>
                                {t('gallery.inputs.checkbox_label')}
                            </CheckboxField>
                            <CheckboxField disabled checked>
                                {t('gallery.inputs.checkbox_label')}
                            </CheckboxField>
                        </div>
                    </Spec>
                    <Spec label="Switch (rest)">
                        <div className="flex items-center gap-3">
                            <Switch
                                aria-label={t('gallery.inputs.switch_label')}
                                checked={switchRest}
                                onCheckedChange={setSwitchRest}
                            />
                            <Switch
                                size="sm"
                                aria-label={`${t('gallery.inputs.switch_label')} sm`}
                                checked={switchRest}
                                onCheckedChange={setSwitchRest}
                            />
                        </div>
                    </Spec>
                    <Spec label='Switch checked (default & size="sm")'>
                        <div className="flex items-center gap-3">
                            <Switch
                                aria-label={t('gallery.inputs.switch_label')}
                                checked={switchFilled}
                                onCheckedChange={setSwitchFilled}
                            />
                            <Switch
                                size="sm"
                                aria-label={`${t('gallery.inputs.switch_label')} sm`}
                                checked={switchFilled}
                                onCheckedChange={setSwitchFilled}
                            />
                        </div>
                    </Spec>
                    <Spec label="Switch aria-invalid">
                        <Switch
                            id="g-switch-invalid"
                            aria-label={t('gallery.inputs.switch_label')}
                            checked={switchInvalid}
                            onCheckedChange={setSwitchInvalid}
                            {...fieldErrorProps('g-switch-invalid', invalidMsg)}
                        />
                        <InputError
                            field="g-switch-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="Switch disabled (off & on)">
                        <div className="flex items-center gap-3">
                            <Switch
                                disabled
                                checked={false}
                                aria-label="Switch disabled off"
                            />
                            <Switch
                                disabled
                                checked
                                aria-label="Switch disabled on"
                            />
                        </div>
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="RadioGroup">
                <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Spec label='RadioGroup orientation="vertical" (rest)'>
                        <RadioGroup
                            orientation="vertical"
                            value={radioRest}
                            onValueChange={setRadioRest}
                            aria-label={t('gallery.inputs.state_rest')}
                        >
                            <RadioGroupItem value="immediate">
                                {t('gallery.inputs.radio_immediate')}
                            </RadioGroupItem>
                            <RadioGroupItem value="confirm">
                                {t('gallery.inputs.radio_confirm')}
                            </RadioGroupItem>
                            <RadioGroupItem value="digest">
                                {t('gallery.inputs.radio_digest')}
                            </RadioGroupItem>
                        </RadioGroup>
                    </Spec>
                    <Spec label='RadioGroup value="confirm"'>
                        <RadioGroup
                            orientation="vertical"
                            value={radioFilled}
                            onValueChange={setRadioFilled}
                            aria-label={t('gallery.inputs.state_filled')}
                        >
                            <RadioGroupItem value="immediate">
                                {t('gallery.inputs.radio_immediate')}
                            </RadioGroupItem>
                            <RadioGroupItem value="confirm">
                                {t('gallery.inputs.radio_confirm')}
                            </RadioGroupItem>
                            <RadioGroupItem value="digest">
                                {t('gallery.inputs.radio_digest')}
                            </RadioGroupItem>
                        </RadioGroup>
                    </Spec>
                    <Spec label="RadioGroup aria-invalid">
                        <RadioGroup
                            id="g-radio-invalid"
                            orientation="vertical"
                            value={radioInvalid}
                            onValueChange={setRadioInvalid}
                            aria-label={t('gallery.inputs.state_invalid')}
                            {...fieldErrorProps('g-radio-invalid', invalidMsg)}
                        >
                            <RadioGroupItem value="immediate">
                                {t('gallery.inputs.radio_immediate')}
                            </RadioGroupItem>
                            <RadioGroupItem value="confirm">
                                {t('gallery.inputs.radio_confirm')}
                            </RadioGroupItem>
                        </RadioGroup>
                        <InputError
                            field="g-radio-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="RadioGroup disabled">
                        <RadioGroup
                            orientation="vertical"
                            value="confirm"
                            disabled
                            aria-label={t('gallery.inputs.state_disabled')}
                        >
                            <RadioGroupItem value="immediate">
                                {t('gallery.inputs.radio_immediate')}
                            </RadioGroupItem>
                            <RadioGroupItem value="confirm">
                                {t('gallery.inputs.radio_confirm')}
                            </RadioGroupItem>
                        </RadioGroup>
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="RadioCards">
                <div className="grid gap-4 rounded-lg border bg-card p-4 lg:grid-cols-2">
                    <Spec label="RadioCards (rest)">
                        <RadioCards
                            label={t('gallery.inputs.state_rest')}
                            value={cardRest}
                            onChange={setCardRest}
                            options={radioCardOptions}
                        />
                    </Spec>
                    <Spec label='RadioCards value="balanced"'>
                        <RadioCards
                            label={t('gallery.inputs.state_filled')}
                            value={cardFilled}
                            onChange={setCardFilled}
                            options={radioCardOptions}
                        />
                    </Spec>
                    <Spec label="RadioCards invalid">
                        <RadioCards
                            label={t('gallery.inputs.state_invalid')}
                            value={cardInvalid}
                            onChange={setCardInvalid}
                            options={radioCardOptions.slice(0, 2)}
                            invalid
                            describedBy="g-cards-invalid-error"
                        />
                        <InputError
                            field="g-cards-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="RadioCards disabled">
                        <RadioCards
                            label={t('gallery.inputs.state_disabled')}
                            value="balanced"
                            onChange={() => {}}
                            options={radioCardOptions.slice(0, 2)}
                            disabled
                        />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="InputOTP">
                <div className="grid gap-4 rounded-lg border bg-card p-4 lg:grid-cols-2">
                    <Spec label="InputOTP (rest)">
                        <InputOTP
                            maxLength={6}
                            value={otpRest}
                            onChange={setOtpRest}
                        >
                            <InputOTPGroup>
                                <InputOTPSlot index={0} />
                                <InputOTPSlot index={1} />
                                <InputOTPSlot index={2} />
                            </InputOTPGroup>
                            <InputOTPSeparator />
                            <InputOTPGroup>
                                <InputOTPSlot index={3} />
                                <InputOTPSlot index={4} />
                                <InputOTPSlot index={5} />
                            </InputOTPGroup>
                        </InputOTP>
                    </Spec>
                    <Spec label='InputOTP value="482910"'>
                        <InputOTP
                            maxLength={6}
                            value={otpFilled}
                            onChange={setOtpFilled}
                        >
                            <InputOTPGroup>
                                <InputOTPSlot index={0} />
                                <InputOTPSlot index={1} />
                                <InputOTPSlot index={2} />
                            </InputOTPGroup>
                            <InputOTPSeparator />
                            <InputOTPGroup>
                                <InputOTPSlot index={3} />
                                <InputOTPSlot index={4} />
                                <InputOTPSlot index={5} />
                            </InputOTPGroup>
                        </InputOTP>
                    </Spec>
                    <Spec label="InputOTP aria-invalid">
                        <InputOTP
                            id="g-otp-invalid"
                            maxLength={6}
                            value={otpInvalid}
                            onChange={setOtpInvalid}
                            {...fieldErrorProps('g-otp-invalid', invalidMsg)}
                        >
                            <InputOTPGroup>
                                <InputOTPSlot index={0} />
                                <InputOTPSlot index={1} />
                                <InputOTPSlot index={2} />
                            </InputOTPGroup>
                            <InputOTPSeparator />
                            <InputOTPGroup>
                                <InputOTPSlot index={3} />
                                <InputOTPSlot index={4} />
                                <InputOTPSlot index={5} />
                            </InputOTPGroup>
                        </InputOTP>
                        <InputError
                            field="g-otp-invalid"
                            message={invalidMsg}
                            className="mt-1"
                        />
                    </Spec>
                    <Spec label="InputOTP disabled">
                        <InputOTP maxLength={6} value="482910" disabled>
                            <InputOTPGroup>
                                <InputOTPSlot index={0} />
                                <InputOTPSlot index={1} />
                                <InputOTPSlot index={2} />
                            </InputOTPGroup>
                            <InputOTPSeparator />
                            <InputOTPGroup>
                                <InputOTPSlot index={3} />
                                <InputOTPSlot index={4} />
                                <InputOTPSlot index={5} />
                            </InputOTPGroup>
                        </InputOTP>
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title="ReplicatorField">
                <div className="rounded-lg border bg-card p-4">
                    <Spec label="ReplicatorField max={5}">
                        <ReplicatorField
                            id="g-headers"
                            label={t('gallery.inputs.headers_label')}
                            columns={[
                                {
                                    key: 'name',
                                    label: t('gallery.inputs.headers_name'),
                                    placeholder: 'X-Custom-Header',
                                    narrow: true,
                                    mono: true,
                                },
                                {
                                    key: 'value',
                                    label: t('gallery.inputs.headers_value'),
                                    placeholder: 'value',
                                    mono: true,
                                },
                            ]}
                            rows={headers}
                            onRowsChange={setHeaders}
                            max={5}
                            addLabel={t('gallery.inputs.headers_add')}
                            rowName={(index) =>
                                t('gallery.inputs.headers_row', {
                                    number: index + 1,
                                })
                            }
                            empty={t('gallery.inputs.headers_empty')}
                            error={() => undefined}
                        />
                    </Spec>
                </div>
            </SubGroup>
        </GallerySection>
    );
}

function NavigationSection({
    section,
    sectionHref,
}: {
    section: string;
    sectionHref: (value: string) => string;
}) {
    const { t } = useTranslation();
    const [period, setPeriod] = useState('30');
    const [weekdays, setWeekdays] = useState<number[]>([1, 2, 3, 4, 5]);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(20);

    const [filterSearch, setFilterSearch] = useState('');
    const [filterClient, setFilterClient] = useState('acme');
    const [filterState, setFilterState] = useState('');
    const [filterIncidentStatus, setFilterIncidentStatus] = useState('open');

    const activeHiddenCount =
        (filterClient ? 1 : 0) +
        (filterState ? 1 : 0) +
        (filterIncidentStatus !== 'open' ? 1 : 0);
    const filtersActive = Boolean(filterSearch) || activeHiddenCount > 0;

    return (
        <GallerySection
            id="navigation"
            title={t('gallery.section.navigation')}
            description={t('gallery.section.navigation_desc')}
        >
            <div className="space-y-6 rounded-lg border bg-card p-4">
                <Spec label="SectionTabs (with muted + separated item)">
                    <SectionTabs
                        label={t('Checks')}
                        value={section}
                        items={[
                            {
                                value: 'overview',
                                label: t('Overview'),
                                href: sectionHref('overview'),
                            },
                            {
                                value: 'uptime',
                                label: t('Uptime'),
                                href: sectionHref('uptime'),
                            },
                            {
                                value: 'certificate',
                                label: t('Certificate'),
                                href: sectionHref('certificate'),
                                muted: true,
                            },
                            {
                                value: 'settings',
                                label: t('Settings'),
                                href: sectionHref('settings'),
                                separated: true,
                            },
                        ]}
                    />
                </Spec>

                <div className="grid gap-4 md:grid-cols-2">
                    <Spec label="SegmentedControl">
                        <SegmentedControl
                            label={t('Time range')}
                            value={period}
                            onValueChange={setPeriod}
                            options={[
                                { value: '24', label: '24 h' },
                                { value: '7', label: '7 d' },
                                { value: '30', label: '30 d' },
                            ]}
                        />
                    </Spec>
                    <Spec label="SegmentedControl disabled">
                        <SegmentedControl
                            label={`${t('Time range')} disabled`}
                            value="30"
                            onValueChange={() => {}}
                            disabled
                            options={[
                                { value: '24', label: '24 h' },
                                { value: '7', label: '7 d' },
                                { value: '30', label: '30 d' },
                            ]}
                        />
                    </Spec>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Spec label='WeekdayToggleGroup numbering="iso"'>
                        <span id="g-weekdays-label" className="sr-only">
                            Weekdays
                        </span>
                        <WeekdayToggleGroup
                            id="g-weekdays"
                            value={weekdays}
                            onValueChange={setWeekdays}
                            numbering="iso"
                            labelledBy="g-weekdays-label"
                        />
                    </Spec>
                    <Spec label="WeekdayToggleGroup disabled">
                        <span id="g-weekdays-dis-label" className="sr-only">
                            Weekdays disabled
                        </span>
                        <WeekdayToggleGroup
                            value={[1, 3, 5]}
                            onValueChange={() => {}}
                            numbering="iso"
                            labelledBy="g-weekdays-dis-label"
                            disabled
                        />
                    </Spec>
                </div>

                <Spec label="ListFooter (paged with Rows selector)">
                    <ListFooter
                        count={t('gallery.nav.single_page_count')}
                        pagination={clientPagination(page, pageSize, 64)}
                        onPageChange={setPage}
                        onPageSizeChange={(nextSize) => {
                            setPageSize(nextSize);
                            setPage(1);
                        }}
                    />
                </Spec>

                <Spec label="ListFooter (single page)">
                    <ListFooter count={t('gallery.nav.single_page_count')} />
                </Spec>

                <Spec label="FilterBar + FilterCombobox + FilterSelectTrigger + SegmentedControl">
                    <FilterBar
                        activeCount={activeHiddenCount}
                        filtersActive={filtersActive}
                        onClearFilters={() => {
                            setFilterSearch('');
                            setFilterClient('');
                            setFilterState('');
                            setFilterIncidentStatus('open');
                        }}
                        search={
                            <Input
                                type="search"
                                aria-label={t('Filter sites…')}
                                placeholder={t('Filter sites…')}
                                value={filterSearch}
                                onChange={(e) =>
                                    setFilterSearch(e.target.value)
                                }
                            />
                        }
                        segmented={
                            <SegmentedControl
                                label={t('Status')}
                                value={filterIncidentStatus}
                                onValueChange={setFilterIncidentStatus}
                                options={[
                                    { value: 'open', label: t('Open') },
                                    { value: 'resolved', label: t('Resolved') },
                                    { value: 'all', label: t('All') },
                                ]}
                            />
                        }
                    >
                        <FilterCombobox
                            ariaLabel={t('Client')}
                            options={comboboxClientOptions}
                            value={filterClient}
                            onValueChange={setFilterClient}
                            onClear={
                                filterClient ? () => setFilterClient('') : null
                            }
                        />
                        <Select
                            value={filterState || 'all'}
                            onValueChange={(value) =>
                                setFilterState(value === 'all' ? '' : value)
                            }
                        >
                            <FilterSelectTrigger
                                aria-label={t('Status')}
                                onClear={
                                    filterState
                                        ? () => setFilterState('')
                                        : null
                                }
                            >
                                <SelectValue placeholder={t('All statuses')} />
                            </FilterSelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {t('All statuses')}
                                </SelectItem>
                                {(
                                    ['ok', 'warning', 'down', 'failed'] as const
                                ).map((st) => (
                                    <SelectItem key={st} value={st}>
                                        {t(statusLabels[st])}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterBar>
                </Spec>

                <Spec label="Disclosure">
                    <Disclosure
                        summary={
                            <span className="text-sm font-medium">
                                {t('gallery.nav.disclosure_summary')}
                            </span>
                        }
                        contentClassName="pt-2 pl-5.5 font-mono text-xs text-muted-foreground"
                    >
                        {t('gallery.nav.disclosure_body')}
                    </Disclosure>
                </Spec>
            </div>
        </GallerySection>
    );
}

function FeedbackSection() {
    const { t } = useTranslation();
    const [filteredEmpty, setFilteredEmpty] = useState(false);

    return (
        <GallerySection
            id="feedback"
            title={t('gallery.section.feedback')}
            description={t('gallery.section.feedback_desc')}
        >
            <SubGroup title="Alert">
                <div className="space-y-3">
                    {alertToneExamples.map((item) => (
                        <Spec
                            key={item.tone}
                            label={`Alert tone="${item.tone}"${item.hasAction ? ' action' : ''}`}
                        >
                            <Alert
                                tone={item.tone}
                                action={
                                    item.hasAction ? (
                                        <Button
                                            variant="outline"
                                            size="xs"
                                            onClick={() =>
                                                toast(t(item.titleKey))
                                            }
                                        >
                                            {t('Run check')}
                                        </Button>
                                    ) : undefined
                                }
                            >
                                <AlertTitle>{t(item.titleKey)}</AlertTitle>
                                <AlertDescription>
                                    {t(item.descKey)}
                                </AlertDescription>
                            </Alert>
                        </Spec>
                    ))}
                </div>
            </SubGroup>

            <SubGroup title="Badge">
                <div className="flex flex-wrap items-center gap-4 rounded-lg border bg-card p-4">
                    <Spec label='Badge variant="outline"'>
                        <Badge>{t('Later')}</Badge>
                    </Spec>
                    <Spec label="Badge + icon">
                        <Badge>
                            <ShieldCheck />
                            TLS 1.3
                        </Badge>
                    </Spec>
                    <Spec label="Badge font-mono tabular-nums">
                        <Badge className="font-mono tabular-nums">64</Badge>
                    </Spec>
                    <Spec label="YouMarker">
                        <YouMarker />
                    </Spec>
                </div>
            </SubGroup>

            <SubGroup title={t('Colour · status')}>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {allSiteStates.map((state) => (
                        <div
                            key={state}
                            className="flex flex-col items-start gap-2 rounded-md border bg-card p-3"
                        >
                            <code className="font-mono text-label text-muted-foreground">
                                state=&quot;{state}&quot;
                            </code>
                            <div className="flex items-center gap-2.5">
                                <StatusDot state={state} />
                                <StatusDot state={state} strip />
                            </div>
                            <StatusBadge state={state} />
                        </div>
                    ))}
                    <div className="flex flex-col items-start gap-2 rounded-md border bg-card p-3">
                        <code className="font-mono text-label text-muted-foreground">
                            state=&quot;failed&quot; snoozed
                        </code>
                        <div className="flex items-center gap-2.5">
                            <StatusDot state="failed" snoozed />
                            <StatusDot state="warning" snoozed />
                        </div>
                        <StatusBadge state="failed" snoozed />
                    </div>
                </div>
            </SubGroup>

            <SubGroup title={t('Check runs')}>
                <div className="space-y-4 rounded-lg border bg-card p-4">
                    <Spec label="RunResultChip (all outcomes)">
                        <div className="flex flex-wrap items-center gap-2">
                            {runOutcomes.map((outcome) => (
                                <RunResultChip
                                    key={outcome}
                                    outcome={outcome}
                                />
                            ))}
                        </div>
                    </Spec>
                    <Spec label="RunGlyph & RunStatus">
                        <div className="flex flex-wrap items-center gap-4">
                            <RunStatus phase="queued" />
                            <RunStatus phase="running" />
                            <span className="inline-flex items-center gap-1.5 text-xs">
                                <RunGlyph
                                    phase="running"
                                    progress={{ done: 612, total: 1204 }}
                                />
                                50 %
                            </span>
                            <RunStatus phase="finished" outcome="ok" />
                        </div>
                    </Spec>
                    <Spec label="RunLine (queued · running + bar + mine · running indeterminate)">
                        <div className="space-y-1">
                            <RunLine
                                phase="queued"
                                label={t('Queued')}
                                detail="#2"
                            />
                            <RunLine
                                phase="running"
                                label={t('Running')}
                                detail="612 / 1,204"
                                progress={{ done: 612, total: 1204 }}
                                bar
                                mine
                            />
                            <RunLine phase="running" label={t('Running')} />
                        </div>
                    </Spec>
                </div>
            </SubGroup>

            <div className="grid gap-4 lg:grid-cols-2">
                <SubGroup title="Spinner & Skeleton">
                    <div className="space-y-4 rounded-lg border bg-card p-4">
                        <Spec label="Spinner">
                            <div className="flex items-center gap-4">
                                <Spinner />
                                <span className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                                    <Spinner />
                                    {t('Loading')}
                                </span>
                            </div>
                        </Spec>
                        <Spec label="Skeleton">
                            <div className="space-y-3">
                                <div className="flex items-center gap-3">
                                    <Skeleton className="size-8 rounded-full" />
                                    <div className="flex-1 space-y-1.5">
                                        <Skeleton className="h-3.5 w-36" />
                                        <Skeleton className="h-3 w-24" />
                                    </div>
                                </div>
                                <GallerySkeleton />
                            </div>
                        </Spec>
                    </div>
                </SubGroup>

                <SubGroup title="Toast & ActionNoteSlot">
                    <div className="space-y-4 rounded-lg border bg-card p-4">
                        <Spec label="toast() triggers (Sonner)">
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast(
                                            t('gallery.feedback.toast_queued'),
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_default')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast.success(
                                            t('gallery.feedback.toast_saved'),
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_success')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast.info(
                                            t('gallery.feedback.toast_queued'),
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_info')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast.warning(
                                            t(
                                                'gallery.feedback.toast_rate_limit',
                                            ),
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_warning')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast.error(
                                            t('gallery.feedback.toast_failed'),
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_error')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    onClick={() =>
                                        toast(
                                            t('gallery.feedback.toast_saved'),
                                            {
                                                action: {
                                                    label: t(
                                                        'gallery.feedback.toast_undo',
                                                    ),
                                                    onClick: () =>
                                                        toast.info(
                                                            t(
                                                                'gallery.feedback.toast_undo',
                                                            ),
                                                        ),
                                                },
                                            },
                                        )
                                    }
                                >
                                    {t('gallery.feedback.toast_action')}
                                </Button>
                            </div>
                        </Spec>

                        <Spec label="ActionNoteSlot (short)">
                            <ActionNoteSlot>
                                {t('gallery.feedback.note_short')}
                            </ActionNoteSlot>
                        </Spec>

                        <Spec label="ActionNoteSlot (clipped · hover/focus/tap)">
                            <div className="max-w-56">
                                <ActionNoteSlot>
                                    {t('gallery.feedback.note_clipped')}
                                </ActionNoteSlot>
                            </div>
                        </Spec>

                        <Spec label="ActionNoteSlot (empty reserved 20px)">
                            <div className="rounded-md border border-dashed px-2">
                                <ActionNoteSlot />
                            </div>
                        </Spec>
                    </div>
                </SubGroup>
            </div>

            <SubGroup title="EmptyState & ContentState">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Spec label="EmptyState (first-run & filtered toggle)">
                        <div className="space-y-2">
                            <CheckboxField
                                checked={filteredEmpty}
                                onCheckedChange={(v) =>
                                    setFilteredEmpty(v === true)
                                }
                            >
                                filtered.active
                            </CheckboxField>
                            <SharedEmptyState
                                icon={Globe}
                                title={t('No sites yet')}
                                description={t('Paste one address or a list.')}
                                action={
                                    <Button
                                        onClick={() => toast(t('Add site'))}
                                    >
                                        <Plus />
                                        {t('Add site')}
                                    </Button>
                                }
                                filtered={{
                                    active: filteredEmpty,
                                    title: t(
                                        'gallery.feedback.empty_filtered_title',
                                    ),
                                    description: t(
                                        'gallery.feedback.empty_filtered_desc',
                                    ),
                                }}
                            />
                        </div>
                    </Spec>
                    <Spec label='ContentState state="error"'>
                        <ContentState state="error" />
                    </Spec>
                </div>
            </SubGroup>
        </GallerySection>
    );
}

function OverlaysSection() {
    const { t } = useTranslation();
    const [dialogOpen, setDialogOpen] = useState(false);
    const [confirmNeutralOpen, setConfirmNeutralOpen] = useState(false);
    const [confirmDestructiveOpen, setConfirmDestructiveOpen] = useState(false);
    const [sheetFormOpen, setSheetFormOpen] = useState(false);
    const [sheetSiteName, setSheetSiteName] = useState('shop.acme.example');
    const [sheetInterval, setSheetInterval] = useState('60');

    const [showResponse, setShowResponse] = useState(true);
    const [showUptime, setShowUptime] = useState(true);
    const [menuSort, setMenuSort] = useState('status');

    const [commandOpen, setCommandOpen] = useState(false);

    return (
        <GallerySection
            id="overlays"
            title={t('gallery.section.overlays')}
            description={t('gallery.section.overlays_desc')}
        >
            <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3">
                <Spec label="Dialog">
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                {t('gallery.overlays.open_dialog')}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {t('gallery.overlays.dialog_title')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('gallery.overlays.dialog_desc')}
                                </DialogDescription>
                            </DialogHeader>
                            <p className="text-sm text-muted-foreground">
                                {t('gallery.overlays.dialog_body')}
                            </p>
                            <DialogFooter>
                                <FormSecondaryAction
                                    onClick={() => setDialogOpen(false)}
                                >
                                    {t('Cancel')}
                                </FormSecondaryAction>
                                <Button
                                    onClick={() => {
                                        setDialogOpen(false);
                                        toast.success(
                                            t('gallery.feedback.toast_saved'),
                                        );
                                    }}
                                >
                                    {t('Save changes')}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </Spec>

                <Spec label="ConfirmDialog (neutral & destructive)">
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setConfirmNeutralOpen(true)}
                        >
                            {t('gallery.overlays.open_confirm_neutral')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => setConfirmDestructiveOpen(true)}
                        >
                            {t('gallery.overlays.open_confirm_destructive')}
                        </Button>
                    </div>
                    <ConfirmDialog
                        open={confirmNeutralOpen}
                        onOpenChange={setConfirmNeutralOpen}
                        title={t('Pause site')}
                        description={t('gallery.overlays.pause_desc')}
                        confirmLabel={t('Pause site')}
                        onConfirm={async () => {
                            toast(t('Pause site'));
                            return true;
                        }}
                    />
                    <ConfirmDialog
                        open={confirmDestructiveOpen}
                        onOpenChange={setConfirmDestructiveOpen}
                        title={t('Delete site')}
                        description={t(
                            'Deleting removes this site, its checks, runs and incidents.',
                        )}
                        confirmLabel={t('Delete site')}
                        destructive
                        onConfirm={async () => {
                            toast.error(t('Site deleted'));
                            return true;
                        }}
                    />
                </Spec>

                <Spec label="Sheet & SheetForm">
                    <div className="flex flex-wrap gap-2">
                        <PeekSheet
                            title={sampleSite.name}
                            description={t('Site details')}
                            trigger={
                                <Button variant="outline">
                                    {t('Open peek')}
                                </Button>
                            }
                        >
                            <StatusBadge state="down" />
                        </PeekSheet>
                        <Sheet
                            open={sheetFormOpen}
                            onOpenChange={setSheetFormOpen}
                        >
                            <SheetTrigger asChild>
                                <Button variant="outline">
                                    {t('gallery.overlays.open_sheet_form')}
                                </Button>
                            </SheetTrigger>
                            <SheetForm
                                title={t('gallery.overlays.sheet_form_title')}
                                description={t(
                                    'gallery.overlays.sheet_form_desc',
                                )}
                                detail={t('gallery.overlays.sheet_form_detail')}
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setSheetFormOpen(false);
                                    toast.success(
                                        t('gallery.feedback.toast_saved'),
                                    );
                                }}
                                footer={
                                    <>
                                        <FormSecondaryAction
                                            onClick={() =>
                                                setSheetFormOpen(false)
                                            }
                                        >
                                            {t('Cancel')}
                                        </FormSecondaryAction>
                                        <Button type="submit">
                                            {t('Save changes')}
                                        </Button>
                                    </>
                                }
                            >
                                <div className="space-y-1.5">
                                    <Label htmlFor="g-sheet-name">
                                        {t('Site')}
                                    </Label>
                                    <Input
                                        id="g-sheet-name"
                                        value={sheetSiteName}
                                        onChange={(e) =>
                                            setSheetSiteName(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="g-sheet-interval">
                                        {t('gallery.inputs.select_placeholder')}
                                    </Label>
                                    <Select
                                        value={sheetInterval}
                                        onValueChange={setSheetInterval}
                                    >
                                        <SelectTrigger
                                            id="g-sheet-interval"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {selectIntervalOptions.map(
                                                (opt) => (
                                                    <SelectItem
                                                        key={opt.value}
                                                        value={opt.value}
                                                    >
                                                        {t(opt.labelKey)}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </SheetForm>
                        </Sheet>
                    </div>
                </Spec>

                <Spec label="DropdownMenu (items · checkbox · radio · disabled · destructive)">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline">
                                {t('gallery.overlays.open_menu')}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-56">
                            <DropdownMenuLabel>
                                {t('gallery.overlays.menu_actions')}
                            </DropdownMenuLabel>
                            <DropdownMenuGroup>
                                <DropdownMenuItem
                                    onSelect={() => toast(t('Run check'))}
                                >
                                    <Play />
                                    {t('Run check')}
                                    <DropdownMenuShortcut>
                                        R
                                    </DropdownMenuShortcut>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => toast(t('Copy'))}
                                >
                                    <Copy />
                                    {t('Copy')}
                                    <DropdownMenuShortcut>
                                        ⌘C
                                    </DropdownMenuShortcut>
                                </DropdownMenuItem>
                                <DropdownMenuItem disabled>
                                    <Wrench />
                                    {t('gallery.overlays.menu_disabled')}
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>
                                {t('gallery.overlays.menu_view')}
                            </DropdownMenuLabel>
                            <DropdownMenuCheckboxItem
                                checked={showResponse}
                                onCheckedChange={(v) =>
                                    setShowResponse(v === true)
                                }
                            >
                                {t('gallery.overlays.menu_col_response')}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuCheckboxItem
                                checked={showUptime}
                                onCheckedChange={(v) =>
                                    setShowUptime(v === true)
                                }
                            >
                                {t('gallery.overlays.menu_col_uptime')}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>
                                {t('gallery.overlays.menu_sort')}
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={menuSort}
                                onValueChange={setMenuSort}
                            >
                                <DropdownMenuRadioItem value="status">
                                    {t('gallery.overlays.menu_sort_status')}
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="name">
                                    {t('gallery.overlays.menu_sort_name')}
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => toast.error(t('Delete site'))}
                            >
                                <Trash2 />
                                {t('Delete site')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </Spec>

                <Spec label="CommandDialog (command palette)">
                    <Button
                        variant="outline"
                        onClick={() => setCommandOpen(true)}
                    >
                        <Search />
                        {t('Open command palette')}
                        <Kbd>⌘K</Kbd>
                    </Button>
                    <CommandDialog
                        open={commandOpen}
                        onOpenChange={setCommandOpen}
                        title={t('Command palette')}
                        description={t('Search sites, clients, actions…')}
                    >
                        <CommandInput
                            placeholder={t('Search sites, clients, actions…')}
                        />
                        <CommandList>
                            <CommandEmpty>
                                {t('gallery.overlays.command_empty')}
                            </CommandEmpty>
                            <CommandGroup heading={t('Go to')}>
                                <CommandItem
                                    onSelect={() => {
                                        setCommandOpen(false);
                                        toast(t('Sites'));
                                    }}
                                >
                                    <Globe />
                                    {t('Sites')}
                                    <CommandShortcut>G S</CommandShortcut>
                                </CommandItem>
                                <CommandItem
                                    onSelect={() => {
                                        setCommandOpen(false);
                                        toast(t('Settings'));
                                    }}
                                >
                                    <Settings />
                                    {t('Settings')}
                                    <CommandShortcut>G T</CommandShortcut>
                                </CommandItem>
                            </CommandGroup>
                            <CommandSeparator />
                            <CommandGroup heading={t('Sites')}>
                                {gallerySites.slice(0, 3).map((site) => (
                                    <CommandItem
                                        key={site.id}
                                        value={site.name}
                                        onSelect={() => {
                                            setCommandOpen(false);
                                            toast(site.name);
                                        }}
                                    >
                                        <Activity />
                                        <span className="min-w-0 flex-1 truncate">
                                            {site.name}
                                        </span>
                                        <StatusBadge state={site.state} />
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </CommandDialog>
                </Spec>

                <Spec label="InfoTooltip & TruncatedText">
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            {t('gallery.overlays.info_sentence')}{' '}
                            <InfoTooltip>
                                {t('gallery.overlays.info_detail')}
                            </InfoTooltip>
                        </p>
                        <div className="max-w-48">
                            <TruncatedText
                                content={t('gallery.overlays.truncated_sample')}
                            >
                                <span className="block truncate text-sm">
                                    {t('gallery.overlays.truncated_sample')}
                                </span>
                            </TruncatedText>
                        </div>
                    </div>
                </Spec>
            </div>

            <div className="grid gap-4 rounded-lg border bg-card p-4 lg:grid-cols-2">
                <Spec label="Tooltip & TooltipDetails (live triggers)">
                    <div className="flex flex-wrap gap-2">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="outline">
                                    {t(
                                        'gallery.overlays.tooltip_plain_trigger',
                                    )}
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {t('gallery.overlays.tooltip_plain_content')}
                            </TooltipContent>
                        </Tooltip>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="outline">
                                    {t(
                                        'gallery.overlays.tooltip_details_trigger',
                                    )}
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <TooltipDetails
                                    subject="shop.acme.example · Uptime"
                                    facts={[
                                        'HTTP 503 · 3,500 ms',
                                        '2026-11-22 09:12:04',
                                    ]}
                                    status={{ state: 'down', label: t('Down') }}
                                    hint={t(
                                        'gallery.overlays.tooltip_hint_text',
                                    )}
                                />
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </Spec>
                <Spec label="TooltipPanel + TooltipDetails (static preview)">
                    <TooltipPanel>
                        <TooltipDetails
                            subject="shop.acme.example · Certificate"
                            facts={['Expires 2026-11-28', "Let's Encrypt R11"]}
                            status={{ state: 'warning', label: t('Warning') }}
                            hint={t('gallery.overlays.tooltip_hint_text')}
                        />
                    </TooltipPanel>
                </Spec>
            </div>
        </GallerySection>
    );
}

function LayoutPatternsSection() {
    const { t } = useTranslation();
    const [showHeaderNote, setShowHeaderNote] = useState(true);
    const [dockState, setDockState] = useState<'clean' | 'dirty' | 'saving'>(
        'dirty',
    );
    const [dockToViewport, setDockToViewport] = useState(false);
    const [formInterval, setFormInterval] = useState('60');
    const [formAlerts, setFormAlerts] = useState(true);

    const dockBar = (
        <FormActions
            status={
                <FormSaveStatus
                    dirty={dockState === 'dirty'}
                    processing={dockState === 'saving'}
                />
            }
        >
            <FormSecondaryAction
                disabled={dockState === 'clean' || dockState === 'saving'}
                onClick={() => setDockState('clean')}
            >
                {t('Discard')}
            </FormSecondaryAction>
            <Button
                type="button"
                disabled={dockState === 'clean' || dockState === 'saving'}
                onClick={() => {
                    setDockState('clean');
                    toast.success(t('gallery.feedback.toast_saved'));
                }}
            >
                {t('Save changes')}
            </Button>
        </FormActions>
    );

    return (
        <GallerySection
            id="layout"
            title={t('gallery.section.layout')}
            description={t('gallery.section.layout_desc')}
        >
            <Spec label='PageHeader headingLevel={2} trail meta overflowSecondary="always" actionNote'>
                <div className="space-y-3 rounded-lg border bg-background p-4 md:p-6">
                    <PageHeader
                        headingLevel={2}
                        trail={
                            <>
                                <TextLink
                                    variant="secondary"
                                    href="/dev/components#layout"
                                >
                                    {sampleClient.name}
                                </TextLink>
                                <span aria-hidden>/</span>
                                <span>{t('Sites')}</span>
                            </>
                        }
                        leading={
                            <SiteAvatar
                                name="shop.acme.example"
                                iconUrl={null}
                                iconBacking="none"
                            />
                        }
                        title="shop.acme.example"
                        count={11}
                        badge={<StatusBadge state="ok" />}
                        meta={
                            <>
                                <span className="font-mono text-xs tabular-nums">
                                    99.98 %
                                </span>
                                <span>Europe/London</span>
                            </>
                        }
                        description={t('gallery.layout.header_desc')}
                        overflowSecondary="always"
                        primaryAction={
                            <Button
                                disabled={showHeaderNote}
                                aria-describedby={
                                    showHeaderNote
                                        ? 'g-page-header-note'
                                        : undefined
                                }
                                onClick={() =>
                                    toast(t('gallery.feedback.toast_queued'))
                                }
                            >
                                <Play />
                                {t('Run all checks…')}
                            </Button>
                        }
                        secondaryActions={
                            <>
                                <PageHeaderAction
                                    variant="outline"
                                    onClick={() => toast(t('Maintenance'))}
                                >
                                    <Wrench />
                                    {t('Maintenance')}
                                </PageHeaderAction>
                                <PageHeaderAction
                                    variant="outline"
                                    onClick={() => toast(t('Pause site'))}
                                >
                                    {t('Pause site')}
                                </PageHeaderAction>
                                <PageHeaderAction
                                    variant="destructive"
                                    onClick={() =>
                                        toast.error(t('Delete site'))
                                    }
                                >
                                    <Trash2 />
                                    {t('Delete site')}
                                </PageHeaderAction>
                            </>
                        }
                        actionNote={{
                            id: 'g-page-header-note',
                            content: showHeaderNote
                                ? t('gallery.feedback.note_short')
                                : undefined,
                        }}
                    />
                    <div className="border-t pt-3">
                        <CheckboxField
                            checked={showHeaderNote}
                            onCheckedChange={(v) =>
                                setShowHeaderNote(v === true)
                            }
                        >
                            {t('gallery.layout.header_toggle_note')}
                        </CheckboxField>
                    </div>
                </div>
            </Spec>

            <Spec label="FormSection">
                <div className="rounded-lg border bg-card px-4">
                    <FormSection
                        title={t('Notifications')}
                        description={t('gallery.layout.form_section_desc')}
                        detail={t('gallery.layout.form_section_detail')}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="g-fs-interval">
                                {t('gallery.inputs.select_placeholder')}
                            </Label>
                            <Select
                                value={formInterval}
                                onValueChange={setFormInterval}
                            >
                                <SelectTrigger
                                    id="g-fs-interval"
                                    className="w-full sm:w-64"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {selectIntervalOptions.map((opt) => (
                                        <SelectItem
                                            key={opt.value}
                                            value={opt.value}
                                        >
                                            {t(opt.labelKey)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-center gap-3">
                            <Switch
                                id="g-fs-alerts"
                                checked={formAlerts}
                                onCheckedChange={setFormAlerts}
                            />
                            <Label htmlFor="g-fs-alerts">
                                {t('gallery.inputs.switch_label')}
                            </Label>
                        </div>
                    </FormSection>
                </div>
            </Spec>

            <Spec label="FormActionsDock + FormActions + FormSaveStatus">
                <div className="overflow-hidden rounded-lg border bg-card">
                    <FormActionsDock>
                        <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <SegmentedControl
                                label={t('gallery.layout.dock_state')}
                                value={dockState}
                                onValueChange={(v) =>
                                    setDockState(
                                        v as 'clean' | 'dirty' | 'saving',
                                    )
                                }
                                options={[
                                    {
                                        value: 'clean',
                                        label: t('gallery.layout.dock_clean'),
                                    },
                                    {
                                        value: 'dirty',
                                        label: t('gallery.layout.dock_dirty'),
                                    },
                                    {
                                        value: 'saving',
                                        label: t('gallery.layout.dock_saving'),
                                    },
                                ]}
                            />
                            <CheckboxField
                                checked={dockToViewport}
                                onCheckedChange={(v) =>
                                    setDockToViewport(v === true)
                                }
                            >
                                {t('gallery.layout.dock_viewport')}
                            </CheckboxField>
                        </div>
                        {!dockToViewport && dockBar}
                    </FormActionsDock>
                </div>
                {dockToViewport && dockBar}
            </Spec>

            <Spec label="Card + CardHeader + CardAction (CardToolbarActions) + StatTile + CardFooter">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            <Bell className="size-4 text-muted-foreground" />
                            {t('Sites monitored')}
                        </CardTitle>
                        <CardDescription>
                            {t('gallery.layout.card_desc')}
                        </CardDescription>
                        <CardAction>
                            <CardToolbarActions>
                                <PageHeaderAction
                                    variant="outline"
                                    onClick={() => toast(t('Edit'))}
                                >
                                    {t('Edit')}
                                </PageHeaderAction>
                                <PageHeaderAction
                                    variant="ghost"
                                    onClick={() => toast(t('Runs'))}
                                >
                                    {t('Runs')}
                                    <ChevronRight />
                                </PageHeaderAction>
                            </CardToolbarActions>
                        </CardAction>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <StatTile
                            label={t('Uptime, 30 days')}
                            value="99.98 %"
                            meta={t('3 incidents · 41 min')}
                        />
                        <StatTile
                            label={t('Sites monitored')}
                            value="64"
                            meta={t('On 4 of 64 sites')}
                            bordered
                        />
                        <StatTile
                            label={t('Scheduled tasks')}
                            value="38"
                            meta="p95 182 ms"
                            bordered
                        />
                    </CardContent>
                    <CardFooter>
                        <span className="mr-auto text-xs text-muted-foreground">
                            {t('gallery.layout.card_footer')}
                        </span>
                        <Button
                            variant="outline"
                            onClick={() => toast(t('Run check'))}
                        >
                            {t('Run check')}
                        </Button>
                    </CardFooter>
                </Card>
            </Spec>

            <Spec label="Table + TableRow (rowEmphasisClass) + RowActions">
                <div className="overflow-hidden rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Site')}</TableHead>
                                <TableHead>{t('Client')}</TableHead>
                                <TableHead>{t('Latest result')}</TableHead>
                                <TableHead className="w-24 text-right">
                                    {t('Actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sampleTableRows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    className={rowEmphasisClass(row.state)}
                                >
                                    <TableCell>
                                        <StatusBadge state={row.state} />
                                    </TableCell>
                                    <TableCell column="name">
                                        <EntityLink
                                            name={row.name}
                                            href="/dev/components#layout"
                                        />
                                    </TableCell>
                                    <TableCell>{row.client}</TableCell>
                                    <TableCell column="code">
                                        {row.summary}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowActions
                                            label={row.name}
                                            actions={
                                                row.singleAction
                                                    ? [
                                                          {
                                                              label: t(
                                                                  'gallery.layout.row_open',
                                                              ),
                                                              onSelect: () =>
                                                                  toast(
                                                                      row.name,
                                                                  ),
                                                          },
                                                      ]
                                                    : [
                                                          {
                                                              label: t(
                                                                  'gallery.layout.row_open',
                                                              ),
                                                              onSelect: () =>
                                                                  toast(
                                                                      row.name,
                                                                  ),
                                                          },
                                                          {
                                                              label: t(
                                                                  'gallery.layout.row_pause',
                                                              ),
                                                              onSelect: () =>
                                                                  toast(
                                                                      t(
                                                                          'Pause site',
                                                                      ),
                                                                  ),
                                                          },
                                                          {
                                                              label: t(
                                                                  'gallery.layout.row_delete',
                                                              ),
                                                              destructive: true,
                                                              onSelect: () =>
                                                                  toast.error(
                                                                      t(
                                                                          'Delete site',
                                                                      ),
                                                                  ),
                                                          },
                                                      ]
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </Spec>

            <Spec label="DataTable (full={true})">
                <DataTable
                    data={gallerySites.slice(0, 4)}
                    clients={clients}
                    full
                    onSelect={(site) => toast(site.name)}
                />
            </Spec>

            <Spec label="ListHeader & ListRow (with rowEmphasisClass & RowActions)">
                <div className="overflow-hidden rounded-lg border bg-card">
                    <ListHeader className="sm:grid sm:grid-cols-[minmax(0,1fr)_8rem_auto]">
                        <span>{t('Site')}</span>
                        <span>{t('Status')}</span>
                        <span>{t('Actions')}</span>
                    </ListHeader>
                    {sampleTableRows.map((row) => (
                        <ListRow
                            key={row.id}
                            className={cn(
                                'grid-cols-[minmax(0,1fr)_auto] items-center sm:grid-cols-[minmax(0,1fr)_8rem_auto]',
                                rowEmphasisClass(row.state),
                            )}
                        >
                            <div className="flex min-w-0 items-center gap-2.5">
                                <StatusDot state={row.state} />
                                <EntityLink
                                    name={row.name}
                                    href="/dev/components#layout"
                                />
                            </div>
                            <div className="hidden sm:block">
                                <StatusBadge state={row.state} />
                            </div>
                            <RowActions
                                label={row.name}
                                actions={[
                                    {
                                        label: t('gallery.layout.row_open'),
                                        onSelect: () => toast(row.name),
                                    },
                                    {
                                        label: t('gallery.layout.row_delete'),
                                        destructive: true,
                                        onSelect: () =>
                                            toast.error(t('Delete site')),
                                    },
                                ]}
                            />
                        </ListRow>
                    ))}
                </div>
            </Spec>

            <div className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-2">
                <Spec label="Avatar (sm · default · lg · AvatarBadge · AvatarGroup)">
                    <div className="flex flex-wrap items-center gap-4">
                        <Avatar size="sm">
                            <AvatarFallback>PR</AvatarFallback>
                        </Avatar>
                        <Avatar>
                            <AvatarFallback>JS</AvatarFallback>
                            <AvatarBadge />
                        </Avatar>
                        <Avatar size="lg">
                            <AvatarFallback>NW</AvatarFallback>
                        </Avatar>
                        <AvatarGroup>
                            <Avatar>
                                <AvatarFallback>AC</AvatarFallback>
                            </Avatar>
                            <Avatar>
                                <AvatarFallback>NW</AvatarFallback>
                            </Avatar>
                            <Avatar>
                                <AvatarFallback>CT</AvatarFallback>
                            </Avatar>
                            <AvatarGroupCount>+4</AvatarGroupCount>
                        </AvatarGroup>
                    </div>
                </Spec>
                <Spec label="Separator (horizontal & vertical)">
                    <div className="space-y-3">
                        <Separator />
                        <div className="flex h-6 items-center gap-3 text-xs text-muted-foreground">
                            <span>{t('Sites')}</span>
                            <Separator orientation="vertical" />
                            <span>{t('Clients')}</span>
                            <Separator orientation="vertical" />
                            <span>{t('Incidents')}</span>
                        </div>
                    </div>
                </Spec>
            </div>
        </GallerySection>
    );
}

function MonitoringSection() {
    const { t } = useTranslation();
    return (
        <GallerySection
            id="monitoring"
            title={t('Monitoring primitives')}
            description={t('gallery.section.monitoring_desc')}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('States')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {states.map((state) => (
                            <StateSentence
                                key={state}
                                state={state}
                                sentence={t('State: {{state}}', {
                                    state: t(statusLabels[state]),
                                })}
                                detail={t('On 4 of 64 sites')}
                            />
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('CheckStrip')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Spec label="CheckStrip">
                            <CheckStrip checks={sampleChecks} />
                        </Spec>
                        <Spec label="CheckStrip runningCheckIds">
                            <CheckStrip
                                checks={sampleChecks}
                                runningCheckIds={[sampleChecks[0]!.id]}
                            />
                        </Spec>
                        <Spec label="GalleryClientMark">
                            <GalleryClientMark
                                name={sampleClient.name}
                                initials="AC"
                                color="#7c5e10"
                            />
                        </Spec>
                        <Spec label="Freshness (live · reconnecting · offline)">
                            <div className="flex flex-wrap gap-2">
                                <Freshness state="live" />
                                <Freshness
                                    state="reconnecting"
                                    lastUpdated="09:26"
                                />
                                <Freshness state="offline" />
                            </div>
                        </Spec>
                        <Spec label="HealthIndicator (ok · warning · check-error)">
                            <div className="space-y-2">
                                <HealthIndicator
                                    state="ok"
                                    label={t('Monitoring healthy')}
                                    updatedAt="4 s"
                                    updatedAtDateTime={new Date(
                                        snapshotNow - 4000,
                                    ).toISOString()}
                                />
                                <HealthIndicator
                                    state="warning"
                                    label={t('Monitoring degraded')}
                                    updatedAt="4 h"
                                    updatedAtDateTime={new Date(
                                        snapshotNow - 4 * 3600000,
                                    ).toISOString()}
                                />
                                <HealthIndicator
                                    state="check-error"
                                    label={t('Monitoring stopped')}
                                    updatedAt="8 h"
                                    updatedAtDateTime={new Date(
                                        snapshotNow - 8 * 3600000,
                                    ).toISOString()}
                                />
                            </div>
                        </Spec>
                    </CardContent>
                </Card>
            </div>

            <Spec label="UptimeBar (90 days)">
                <UptimeBar
                    buckets={Array.from({ length: 90 }, (_, day) =>
                        bucketForState(
                            day === 40 ? 'down' : day === 65 ? 'warning' : 'ok',
                            new Date(Date.UTC(2026, 9, 1 + day)).toISOString(),
                            100,
                        ),
                    )}
                />
            </Spec>

            <Spec label="MetricTile">
                <div className="grid gap-4 md:grid-cols-3">
                    <MetricTile
                        label={t('Uptime, 30 days')}
                        value="99.98 %"
                        delta={t('3 incidents · 41 min')}
                        data={sampleSite.response}
                    />
                    <MetricTile
                        label={t('Expiring in 30 days')}
                        value="7"
                        data={[]}
                        state="warning"
                    />
                    <MetricTile
                        label={t('Scheduled tasks')}
                        value="38"
                        data={[]}
                        state="failed"
                    />
                </div>
            </Spec>

            <div className="grid gap-4 md:grid-cols-2">
                <Spec label='Sparkline state="down"'>
                    <Sparkline data={sampleSite.response} state="down" />
                </Spec>
                <Spec label="ScoreRing (92 · 61 · 38)">
                    <div className="flex gap-4">
                        <ScoreRing score={92} label={t('Performance')} />
                        <ScoreRing score={61} label={t('Accessibility')} />
                        <ScoreRing score={38} label={t('SEO')} />
                    </div>
                </Spec>
            </div>

            <Spec label="ExpiryRow">
                <ExpiryRow
                    label="shop.acme.example"
                    daysLeft={6}
                    windowDays={90}
                    state="warning"
                />
            </Spec>

            <div className="grid gap-5 md:grid-cols-2">
                <div className="space-y-3">
                    <Spec label="ScheduleCard">
                        <ScheduleCard
                            next="09:27:01"
                            last="09:26:01"
                            duration="180–420 ms"
                        />
                    </Spec>
                    <Spec label="EvidenceBlock">
                        <EvidenceBlock
                            title={t('Latest result')}
                            rows={[
                                { label: t('Status'), value: 'HTTP 503' },
                                { label: t('Time'), value: '09:13' },
                            ]}
                        />
                    </Spec>
                    <Spec label="DiffBlock">
                        <DiffBlock before="192.0.2.1" after="192.0.2.2" />
                    </Spec>
                    <Spec label="AttentionRow">
                        <AttentionRow
                            site={sampleSite}
                            summary="HTTP 503"
                            state="down"
                            href="/sites"
                        />
                    </Spec>
                    <Spec label="RuleRow">
                        <div>
                            <RuleRow
                                name={t('Email alert')}
                                detail={t('Immediately')}
                                enabled
                            />
                            <RuleRow
                                name={t('Email alert')}
                                detail={t('Paused')}
                                enabled={false}
                            />
                        </div>
                    </Spec>
                    <Spec label="ShownOnceSecret">
                        <ShownOnceSecret
                            label={t('API token')}
                            value="demo-token-not-a-secret"
                        />
                    </Spec>
                </div>
                <div className="space-y-3">
                    <Spec label="EventTimeline">
                        <EventTimeline
                            events={[
                                ...dashboardEvents.slice(0, 2),
                                ...overviewEvents(sampleSite),
                            ]}
                        />
                    </Spec>
                </div>
            </div>
        </GallerySection>
    );
}

export default function Components() {
    const { t, i18n } = useTranslation();
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const { url } = usePage();
    const query = new URL(url, 'http://heptapod-app');
    const currentSection = query.searchParams.get('section');
    const section =
        currentSection === 'uptime' ||
        currentSection === 'certificate' ||
        currentSection === 'settings'
            ? currentSection
            : 'overview';
    const sectionHref = (value: string) => {
        const target = new URL(query);
        target.searchParams.set('section', value);
        return target.pathname + target.search;
    };

    return (
        <PageContainer data-foundation-gallery>
            <Head title={t('Components')} />
            <header className="sticky top-0 z-10 -mx-4 border-b bg-background/95 px-4 py-3 backdrop-blur-sm md:-mx-6 md:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-page-title">{t('Components')}</h1>
                        <p className="text-xs text-muted-foreground">
                            {t('gallery.subtitle')}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <SegmentedControl
                            label={t('Theme')}
                            value={resolvedAppearance}
                            onValueChange={(value) =>
                                updateAppearance(value as ResolvedAppearance)
                            }
                            options={[
                                {
                                    value: 'light',
                                    label: (
                                        <>
                                            <Sun aria-hidden />
                                            {t('Light')}
                                        </>
                                    ),
                                },
                                {
                                    value: 'dark',
                                    label: (
                                        <>
                                            <Moon aria-hidden />
                                            {t('Dark')}
                                        </>
                                    ),
                                },
                            ]}
                        />
                        <SegmentedControl
                            label={t('Language')}
                            value={i18n.language}
                            onValueChange={(value) =>
                                setLocale(value as Locale)
                            }
                            options={[
                                { value: 'en', label: 'EN' },
                                { value: 'de', label: 'DE' },
                            ]}
                        />
                    </div>
                </div>
                <div
                    role="region"
                    aria-label={t('gallery.jump_to')}
                    className="mt-2.5 flex items-center gap-1 overflow-x-auto pb-0.5"
                >
                    {gallerySections.map((item) => (
                        <Button key={item.id} variant="ghost" size="xs" asChild>
                            <a href={`#${item.id}`}>{t(item.labelKey)}</a>
                        </Button>
                    ))}
                </div>
            </header>

            <div className="space-y-8">
                <FoundationsSection />
                <ActionsSection />
                <InputsSection />
                <NavigationSection
                    section={section}
                    sectionHref={sectionHref}
                />
                <FeedbackSection />
                <OverlaysSection />
                <LayoutPatternsSection />
                <MonitoringSection />
            </div>
        </PageContainer>
    );
}

function StateSentence({
    state,
    sentence,
    detail,
    action,
    suffix,
}: {
    state: Heptapod.Enums.SiteStatus;
    sentence: ReactNode;
    detail?: ReactNode;
    action?: ReactNode;
    suffix?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0 space-y-1">
                <h2 className="flex flex-wrap items-center gap-3 text-headline">
                    <StatusBadge state={state} />
                    {sentence}
                    {suffix}
                </h2>
                {detail && (
                    <p className="text-sm text-muted-foreground">{detail}</p>
                )}
            </div>
            {action}
        </div>
    );
}

function GalleryClientMark({
    name,
    initials,
    color,
    size = 'md',
}: {
    name: string;
    initials: string;
    color: string;
    size?: 'sm' | 'md';
}) {
    return (
        <span
            role="img"
            aria-label={name}
            className={`inline-flex shrink-0 items-center justify-center rounded-md font-mono font-semibold text-white ${size === 'sm' ? 'size-4 text-mark' : 'size-7 text-xs'}`}
            style={{ backgroundColor: color }}
        >
            {initials}
        </span>
    );
}

function HealthIndicator({
    state,
    label,
    updatedAt,
    updatedAtDateTime,
}: {
    state: 'ok' | 'warning' | 'check-error';
    label: string;
    updatedAt: string;
    updatedAtDateTime: string;
}) {
    return (
        <div className="flex min-h-8 items-center gap-2 rounded-md border border-border-soft px-2 text-xs text-muted-foreground">
            <StatusDot state={state} label={label} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <time
                dateTime={updatedAtDateTime}
                className="font-mono tabular-nums"
            >
                {updatedAt}
            </time>
        </div>
    );
}

function Freshness({
    state,
    lastUpdated,
}: {
    state: 'live' | 'reconnecting' | 'offline';
    lastUpdated?: string;
}) {
    const { t } = useTranslation();
    return (
        <StatusBadge
            state={state === 'live' ? 'ok' : 'warning'}
            label={
                state === 'live'
                    ? t('Live')
                    : state === 'reconnecting'
                      ? t('Reconnecting · data from {{time}}', {
                            time: lastUpdated ?? '—',
                        })
                      : t('Offline')
            }
        />
    );
}

function PeekSheet({
    title,
    description,
    trigger,
    children,
    open,
    onOpenChange,
}: {
    title: string;
    description: string;
    trigger?: ReactNode;
    children: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent className="w-full overflow-y-auto sm:max-w-[520px]">
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>
                <div className="p-4">{children}</div>
            </SheetContent>
        </Sheet>
    );
}

function DiffBlock({ before, after }: { before: string; after: string }) {
    const { t } = useTranslation();
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <EvidenceBlock
                title={t('Before')}
                rows={[{ label: t('Value'), value: before }]}
            />
            <EvidenceBlock
                title={t('After')}
                rows={[{ label: t('Value'), value: after }]}
            />
        </div>
    );
}

function ExpiryRow({
    label,
    daysLeft,
    windowDays,
    state,
}: {
    label: string;
    daysLeft: number;
    windowDays: number;
    state: Heptapod.Enums.SiteStatus;
}) {
    const { t } = useTranslation();
    return (
        <div className="flex items-center gap-3 text-sm">
            <StatusDot state={state} />
            <TruncatedText content={label}>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </TruncatedText>
            <div className="h-1 w-28 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full"
                    style={{
                        backgroundColor: statusColor[state],
                        width: `${Math.min(100, Math.max(0, (daysLeft / windowDays) * 100))}%`,
                    }}
                />
            </div>
            <span className="font-mono text-xs tabular-nums">
                {t('{{count}} d', { count: daysLeft })}
            </span>
        </div>
    );
}

function ScheduleCard({
    next,
    last,
    duration,
    interval,
    showRunsLink = true,
}: {
    next: ReactNode;
    last: ReactNode;
    duration: string;
    interval?: string;
    showRunsLink?: boolean;
}) {
    const { t } = useTranslation();
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Schedule')}</CardTitle>
                {showRunsLink && (
                    <CardAction>
                        <Button variant="ghost" disabled>
                            {t('Runs')} <ChevronRight className="size-4" />
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                <dl className="grid grid-cols-[6rem_1fr] gap-y-2 text-xs">
                    {interval && (
                        <>
                            <dt className="text-muted-foreground">
                                {t('Every')}
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {interval}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">{t('Next run')}</dt>
                    <dd className="font-mono tabular-nums">{next}</dd>
                    <dt className="text-muted-foreground">{t('Last run')}</dt>
                    <dd className="font-mono tabular-nums">{last}</dd>
                    <dt className="text-muted-foreground">
                        {t('Usually takes')}
                    </dt>
                    <dd className="font-mono tabular-nums">{duration}</dd>
                </dl>
                {showRunsLink && (
                    <p className="text-xs text-foreground-faint">
                        {t('Every run is listed in Runs')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function AttentionRow({
    site,
    summary,
    state,
    href,
}: {
    site: Heptapod.Data.Ui.SiteRowData;
    summary: string;
    state: Heptapod.Enums.SiteStatus;
    href: string;
}) {
    return (
        <Link
            href={href}
            prefetch
            className={cn(
                'flex min-h-10 items-center gap-3 border-b px-3 text-sm',
                rowTargetClass,
            )}
        >
            <StatusDot state={state} />
            <TruncatedText content={site.name}>
                <span className="min-w-0 flex-1 truncate">{site.name}</span>
            </TruncatedText>
            <TruncatedText content={summary}>
                <span className="hidden min-w-0 flex-1 truncate text-muted-foreground sm:block">
                    {summary}
                </span>
            </TruncatedText>
            <StatusBadge state={state} />
        </Link>
    );
}

function RuleRow({
    name,
    detail,
    enabled,
}: {
    name: string;
    detail: string;
    enabled: boolean;
}) {
    return (
        <div className="flex min-h-10 items-center gap-3 border-b px-3 text-sm">
            <TruncatedText content={name}>
                <span className="min-w-0 flex-1 truncate">{name}</span>
            </TruncatedText>
            <span className="text-xs text-muted-foreground">{detail}</span>
            <StatusBadge
                state={enabled ? 'ok' : 'disabled'}
                label={enabled ? 'Enabled' : 'Disabled'}
            />
        </div>
    );
}

function GallerySkeleton() {
    return (
        <div className="space-y-3" aria-busy="true">
            <Skeleton className="h-6 w-1/2" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}
