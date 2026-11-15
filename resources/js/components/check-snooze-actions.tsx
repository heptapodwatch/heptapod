import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ConfirmDialog, confirmVisit } from '@/components/confirm-dialog';
import { FormSecondaryAction } from '@/components/form-section';
import InputError from '@/components/input-error';
import {
    PageHeaderAction,
    useInActionsMenu,
} from '@/components/page-header-actions';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import { DateTimeField } from '@/components/ui/date-time-field';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
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

/**
 * The check page's secondary actions. Active: one Snooze menu (presets, Until a date…, then Turn check off… for
 * Owner/Admin). Snoozed: End snooze. Inside the header's More actions (below 768 px) the choices are its own items.
 */
export function CheckSnoozeActions({
    siteId,
    check,
    checkName,
    canTurnOff,
    onDone,
}: {
    siteId: number;
    check: Heptapod.Data.Ui.CheckStripData;
    checkName: string;
    canTurnOff: boolean;
    /** Closes the header's More actions after a choice. */
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const inMenu = useInActionsMenu();
    const [confirmOff, setConfirmOff] = useState(false);
    const [customOpen, setCustomOpen] = useState(false);
    const route = { site: siteId, check: check.id };
    if (isSnoozed(check, Date.now())) {
        return (
            <PageHeaderAction
                variant="outline"
                onClick={() => {
                    onDone();
                    router.delete(checkUnsnooze.url(route), {
                        preserveScroll: true,
                    });
                }}
            >
                {t('End snooze')}
            </PageHeaderAction>
        );
    }
    const snoozeFor = (minutes: number) => {
        onDone();
        router.post(
            checkSnooze.url(route),
            { minutes },
            { preserveScroll: true },
        );
    };
    const items = inMenu ? (
        <>
            {presets.map(([label, minutes]) => (
                <PageHeaderAction
                    key={minutes}
                    variant="outline"
                    onClick={() => snoozeFor(minutes)}
                >
                    {t('Snooze')} · {t(label)}
                </PageHeaderAction>
            ))}
            <PageHeaderAction
                variant="outline"
                onClick={() => {
                    onDone();
                    setCustomOpen(true);
                }}
            >
                {t('Snooze until…')}
            </PageHeaderAction>
            {canTurnOff && (
                <PageHeaderAction
                    variant="outline"
                    onClick={() => {
                        onDone();
                        setConfirmOff(true);
                    }}
                >
                    {t('Turn check off…')}
                </PageHeaderAction>
            )}
        </>
    ) : (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    {t('Snooze')}
                    <ChevronDown aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {presets.map(([label, minutes]) => (
                    <DropdownMenuItem
                        key={minutes}
                        onSelect={() => snoozeFor(minutes)}
                    >
                        {t(label)}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuItem onSelect={() => setCustomOpen(true)}>
                    {t('Until a date…')}
                </DropdownMenuItem>
                {canTurnOff && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => setConfirmOff(true)}>
                            {t('Turn check off…')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
    return (
        <>
            {items}
            <ConfirmDialog
                open={confirmOff}
                onOpenChange={setConfirmOff}
                title={t('Turn off the {{check}} check?', { check: checkName })}
                description={t('check_snooze.turn_off_consequence')}
                confirmLabel={t('Turn check off')}
                onConfirm={() =>
                    confirmVisit((callbacks) =>
                        router.post(
                            checkDisable.url(route),
                            {},
                            { preserveScroll: true, ...callbacks },
                        ),
                    )
                }
            />
            <SnoozeUntilSheet
                open={customOpen}
                onOpenChange={setCustomOpen}
                url={checkSnooze.url(route)}
            />
        </>
    );
}

function SnoozeUntilSheet({
    open,
    onOpenChange,
    url,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
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
                title={t('Snooze check')}
                description={t('check_snooze.sheet_description')}
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
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={form.processing || instant === null}
                        >
                            {t('Snooze')}
                        </Button>
                    </>
                }
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
