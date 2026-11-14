import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    AlertSensitivityOptions,
    draftOf,
    inheritedDraft,
    sensitivityChoice,
    sensitivityLabel,
    validSensitivity,
    type Sensitivity,
    type SensitivityBounds,
    type SensitivityDraft,
} from '@/components/alert-sensitivity-options';
import {
    FormActions,
    FormSaveStatus,
    FormSecondaryAction,
    FormSection,
} from '@/components/form-section';
import { SheetForm } from '@/components/sheet-form';
import { Button } from '@/components/ui/button';
import { Sheet } from '@/components/ui/sheet';
import { update as updateTeam } from '@/routes/settings/alerts';
import { update as updateSite } from '@/routes/sites';

export function TeamAlertSensitivity({
    saved,
    presets,
    bounds,
}: {
    saved: Sensitivity;
    presets: Sensitivity[];
    bounds: SensitivityBounds;
}) {
    const { t } = useTranslation();
    const form = useForm<SensitivityDraft>(draftOf(saved));
    // Discard also drops typed text the draft could not hold (an unparsable number lives in the fields).
    const [draftKey, setDraftKey] = useState(0);
    return (
        <form
            noValidate
            id="team-alert-sensitivity-form"
            data-team-alert-sensitivity
            onSubmit={(event) => {
                event.preventDefault();
                if (
                    !validSensitivity(
                        form.data,
                        bounds,
                        saved.alert_after_minutes,
                    )
                )
                    return;
                form.put(updateTeam.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.setDefaults(),
                });
            }}
        >
            <FormSection
                title={t('alert_sensitivity.title')}
                description={t('alert_sensitivity.intro')}
                detail={t('alert_sensitivity.intro_detail')}
            >
                <AlertSensitivityOptions
                    key={draftKey}
                    value={form.data}
                    savedMinutes={saved.alert_after_minutes}
                    bounds={bounds}
                    presets={presets}
                    onChange={(value) => form.setData(value)}
                    errors={form.errors}
                    disabled={form.processing}
                />
            </FormSection>
            <FormActions
                status={
                    <FormSaveStatus
                        dirty={form.isDirty}
                        processing={form.processing}
                    />
                }
            >
                <FormSecondaryAction
                    size="sm"
                    disabled={!form.isDirty || form.processing}
                    onClick={() => {
                        form.resetAndClearErrors();
                        setDraftKey((key) => key + 1);
                    }}
                >
                    {t('Discard')}
                </FormSecondaryAction>
                <Button
                    type="submit"
                    form="team-alert-sensitivity-form"
                    size="sm"
                    disabled={
                        !form.isDirty ||
                        form.processing ||
                        !validSensitivity(
                            form.data,
                            bounds,
                            saved.alert_after_minutes,
                        )
                    }
                >
                    {t('Save')}
                </Button>
            </FormActions>
        </form>
    );
}

function SiteSensitivitySheet({
    siteId,
    saved,
    team,
    presets,
    bounds,
    onClose,
}: {
    siteId: number;
    saved: Sensitivity;
    team: Sensitivity;
    presets: Sensitivity[];
    bounds: SensitivityBounds;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm<SensitivityDraft>(
        saved.inherited ? inheritedDraft : draftOf(saved),
    );
    const valid =
        sensitivityChoice(form.data, presets) === 'inherit' ||
        validSensitivity(form.data, bounds, saved.alert_after_minutes);
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <SheetForm
                title={t('alert_sensitivity.title')}
                description={t('alert_sensitivity.site_intro')}
                aria-busy={form.processing}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!valid) return;
                    form.patch(updateSite.url(siteId), {
                        preserveScroll: true,
                        onSuccess: onClose,
                    });
                }}
                footer={
                    <>
                        <FormSecondaryAction
                            disabled={form.processing}
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </FormSecondaryAction>
                        <Button
                            type="submit"
                            disabled={
                                !form.isDirty || form.processing || !valid
                            }
                        >
                            {t(form.processing ? 'Saving…' : 'Save')}
                        </Button>
                    </>
                }
            >
                <AlertSensitivityOptions
                    value={form.data}
                    savedMinutes={saved.alert_after_minutes}
                    bounds={bounds}
                    team={team}
                    presets={presets}
                    onChange={(value) => form.setData(value)}
                    errors={form.errors}
                    disabled={form.processing}
                />
            </SheetForm>
        </Sheet>
    );
}

export function SiteAlertSensitivity({
    siteId,
    saved,
    team,
    presets,
    bounds,
}: {
    siteId: number;
    saved: Sensitivity;
    team: Sensitivity;
    presets: Sensitivity[];
    bounds: SensitivityBounds;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    return (
        <>
            <div
                data-site-alert-sensitivity
                className="flex items-center justify-between gap-3 rounded-lg border p-4"
            >
                <p className="min-w-0 text-sm">
                    {saved.inherited
                        ? t('alert_sensitivity.team_default', {
                              preset: sensitivityLabel(saved, t),
                          })
                        : t('alert_sensitivity.override', {
                              preset: sensitivityLabel(saved, t),
                          })}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                >
                    {t('alert_sensitivity.change')}
                </Button>
            </div>
            {open && (
                <SiteSensitivitySheet
                    siteId={siteId}
                    saved={saved}
                    team={team}
                    presets={presets}
                    bounds={bounds}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}
