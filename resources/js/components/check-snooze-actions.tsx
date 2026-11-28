import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeaderAction } from '@/components/page-header-actions';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import { DateTimeField } from '@/components/ui/date-time-field';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Sheet } from '@/components/ui/sheet';
import { zonedInstant, zonedWallTime } from '@/lib/date-time';
import { isSnoozed } from '@/lib/status';
import {
    disable as checkDisable,
    snooze as checkSnooze,
    unsnooze as checkUnsnooze,
} from '@/routes/sites/checks';

const presets = [
    ['For 1 hour', 60],
    ['For 1 day', 1440],
    ['For 7 days', 10080],
] as const;

/** The check header's Snooze menu (SnoozeActions). */
export function CheckSnoozeActions({
    siteId,
    check,
}: {
    siteId: number;
    check: Heptapod.Data.Ui.CheckStripData;
}) {
    const { t } = useTranslation();
    const route = { site: siteId, check: check.id };
    return (
        <SnoozeActions
            snoozed={isSnoozed(check, Date.now())}
            snoozeUrl={checkSnooze.url(route)}
            unsnoozeUrl={checkUnsnooze.url(route)}
            sheetTitle={t('Snooze check')}
            sheetDescription={t('check_snooze.sheet_description')}
        />
    );
}

/**
 * A header's Snooze menu, for checks and servers alike: presets and Until a date…, or End snooze while snoozed. The
 * trigger keeps one width for both labels, so snoozing never moves the actions next to it.
 */
export function SnoozeActions({
    snoozed,
    snoozeUrl,
    unsnoozeUrl,
    sheetTitle,
    sheetDescription,
}: {
    snoozed: boolean;
    snoozeUrl: string;
    unsnoozeUrl: string;
    sheetTitle: string;
    sheetDescription: string;
}) {
    const { t } = useTranslation();
    const [customOpen, setCustomOpen] = useState(false);
    const snoozeFor = (minutes: number) =>
        router.post(snoozeUrl, { minutes }, { preserveScroll: true });
    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">
                        <span className="grid">
                            {[false, true].map((state) => (
                                <span
                                    key={String(state)}
                                    aria-hidden={state !== snoozed || undefined}
                                    className={
                                        state === snoozed
                                            ? 'col-start-1 row-start-1'
                                            : 'invisible col-start-1 row-start-1'
                                    }
                                >
                                    {t(
                                        state
                                            ? 'site_sections.snoozed'
                                            : 'Snooze',
                                    )}
                                </span>
                            ))}
                        </span>
                        <ChevronDown aria-hidden />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {snoozed ? (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.delete(unsnoozeUrl, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {t('End snooze')}
                        </DropdownMenuItem>
                    ) : (
                        <>
                            {presets.map(([label, minutes]) => (
                                <DropdownMenuItem
                                    key={minutes}
                                    onSelect={() => snoozeFor(minutes)}
                                >
                                    {t(label)}
                                </DropdownMenuItem>
                            ))}
                            <DropdownMenuItem
                                onSelect={() => setCustomOpen(true)}
                            >
                                {t('Until a date…')}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <SnoozeUntilSheet
                open={customOpen}
                onOpenChange={setCustomOpen}
                url={snoozeUrl}
                title={sheetTitle}
                description={sheetDescription}
            />
        </>
    );
}

/** "Turn check off…" in the check header's More actions, with its confirmation. */
export function CheckTurnOffAction({
    siteId,
    checkId,
    checkName,
}: {
    siteId: number;
    checkId: number;
    checkName: string;
}) {
    const { t } = useTranslation();
    const [confirmOff, setConfirmOff] = useState(false);
    return (
        <>
            <PageHeaderAction
                variant="outline"
                onClick={() => setConfirmOff(true)}
            >
                {t('Turn check off…')}
            </PageHeaderAction>
            <ConfirmDialog
                open={confirmOff}
                onOpenChange={setConfirmOff}
                title={t('Turn off the {{check}} check?', { check: checkName })}
                description={t('check_snooze.turn_off_consequence')}
                confirmLabel={t('Turn check off')}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            checkDisable.url({ site: siteId, check: checkId }),
                            {},
                            { preserveScroll: true, ...callbacks },
                        ),
                    )
                }
            />
        </>
    );
}

function SnoozeUntilSheet({
    open,
    onOpenChange,
    url,
    title,
    description,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    title: string;
    description: string;
}) {
    const { t } = useTranslation();
    const { timezone } = usePage().props;
    const tomorrow = () =>
        zonedWallTime(
            new Date(Date.now() + 86_400_000).toISOString(),
            timezone,
        );
    const form = useForm({ snooze_until: tomorrow() });
    const [invalid, setInvalid] = useState(false);
    const instant = zonedInstant(form.data.snooze_until, timezone);
    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.setData('snooze_until', tomorrow());
                    form.clearErrors();
                }
                onOpenChange(next);
            }}
        >
            <SheetForm
                title={title}
                description={description}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (instant === null || invalid) return;
                    // The field holds a wall time in the account timezone; the server takes the instant.
                    form.transform(() => ({ snooze_until: instant }));
                    form.post(url, {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    });
                }}
                actions={{
                    kind: 'action',
                    label: t('Snooze'),
                    valid: instant !== null && !invalid,
                    busy: form.processing,
                    onCancel: () => onOpenChange(false),
                }}
            >
                <div className="space-y-1.5">
                    <Label htmlFor="snooze-until">{t('Snooze until')}</Label>
                    <DateTimeField
                        id="snooze-until"
                        value={form.data.snooze_until}
                        onValueChange={(value) =>
                            form.setData('snooze_until', value)
                        }
                        onInvalidChange={setInvalid}
                        timezone={timezone}
                        invalid={!!form.errors.snooze_until || invalid}
                        describedBy="snooze-until-error"
                    />
                    <InputError
                        field="snooze-until"
                        message={
                            invalid
                                ? 'Enter the date and time in 24-hour format.'
                                : form.errors.snooze_until
                        }
                    />
                </div>
            </SheetForm>
        </Sheet>
    );
}
