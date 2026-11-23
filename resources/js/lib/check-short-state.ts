import type { TFunction } from 'i18next';
import type { useFormat } from '@/hooks/use-format';
import { checkState, isSnoozed, statusLabels } from '@/lib/status';

type Check = Heptapod.Data.Ui.CheckStripData;
type Format = ReturnType<typeof useFormat>;

function numericParam(
    params: Record<string, string | number | boolean | null>,
    key: string,
): number | null {
    const value = params[key];
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function presentationBrokenCount(check: Check): number | null {
    const parts = check.presentation?.parts ?? [];
    let total = 0;
    let matched = false;
    for (const part of parts) {
        if (
            part.key === 'broken_links.presentation.internal' ||
            part.key === 'broken_links.presentation.external'
        ) {
            const count = numericParam(part.params, 'count');
            if (count !== null) {
                total += count;
                matched = true;
            }
        }
    }
    return matched ? total : null;
}

function baseShortState(check: Check, t: TFunction, format: Format): string {
    const state = checkState(check);
    if (check.key === 'broken_links') {
        const count = presentationBrokenCount(check);
        if (count !== null && count > 0) {
            return t('site_sections.broken_count', {
                count,
                formattedCount: format.number(count),
            });
        }
        if (state === 'ok') return t('OK');
    }

    if (check.key === 'sitemap') {
        const count = numericParam(check.summary_params, 'count');
        if (
            count !== null &&
            count > 0 &&
            (state === 'failed' || state === 'warning')
        ) {
            return t('site_sections.issue_count', {
                count,
                formattedCount: format.number(count),
            });
        }
        if (state === 'ok') return t('OK');
    }

    if (check.key === 'certificate_health' || check.key === 'domain') {
        const days = numericParam(check.summary_params, 'days');
        if (days !== null) {
            if (state === 'warning') {
                return t('site_sections.expires_in_days', {
                    count: days,
                    formattedCount: format.number(days),
                });
            }
            if (state === 'ok') {
                return t('site_sections.valid_days', {
                    count: days,
                    formattedCount: format.number(days),
                });
            }
        }
    }

    if (check.key === 'uptime') {
        if (state === 'ok') return t('Up');
        if (state === 'down') return t('Down');
    }

    if (check.key === 'performance' && state === 'ok') {
        const avg = numericParam(check.summary_params, 'average_ms');
        if (avg !== null) {
            return `${format.milliseconds(avg)} ${t('ms')}`;
        }
    }

    if (check.presentation?.history_label) {
        return format.presentationPart(check.presentation.history_label);
    }

    return t(statusLabels[state]);
}

/** Compact one-line status fact for the vertical site sections navigation. */
export function checkShortState(
    check: Check,
    t: TFunction,
    format: Format,
    now: number = Date.now(),
): string {
    if (!check.enabled) return t('Off');
    if (check.unavailable_reason) return t('Unavailable');
    if (check.freshness_suppressed_by) return t('No current data');
    if (check.pending_recheck) return t('Pending');
    if (check.not_fresh) return t('freshness.not_fresh');

    const base = baseShortState(check, t, format);
    if (isSnoozed(check, now)) {
        const state = checkState(check);
        return state === 'ok'
            ? t('site_sections.snoozed')
            : `${t('site_sections.snoozed')} · ${base}`;
    }
    return base;
}
