import type { TFunction } from 'i18next';
import type { useFormat } from '@/hooks/use-format';
import { checkState, isSnoozed, statusLabels } from '@/lib/status';

type Check = Heptapod.Data.Ui.CheckStripData;
type Format = ReturnType<typeof useFormat>;
type Part = Heptapod.Contracts.CheckRunPresentationPartData;

function numericParam(part: Part, key: string): number | null {
    const value = part.params[key];
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** Broken links: the presenter's internal plus external broken counts. */
function brokenCount(parts: Part[]): number | null {
    const counts = parts
        .filter(
            (part) =>
                part.key === 'broken_links.presentation.internal' ||
                part.key === 'broken_links.presentation.external',
        )
        .map((part) => numericParam(part, 'count'));
    return counts.length > 0 && counts.every((count) => count !== null)
        ? counts.reduce<number>((sum, count) => sum + count!, 0)
        : null;
}

const domainDateKeys: Record<string, string> = {
    'checks.presentation.domain_expires': 'site_sections.expires_on',
    'checks.presentation.domain_valid_until': 'site_sections.valid_until',
    'checks.presentation.domain_expired': 'site_sections.expired_on',
};

/**
 * A fact only where the server states it (a count or date from the presenter); otherwise the history label or the state
 * word, never a number guessed from generic parameters.
 */
function baseShortState(check: Check, t: TFunction, format: Format): string {
    const parts = check.presentation?.parts ?? [];
    if (check.key === 'broken_links') {
        const count = brokenCount(parts);
        if (count !== null && count > 0) {
            return t('site_sections.broken_count', {
                count,
                formattedCount: format.number(count),
            });
        }
    }
    if (check.key === 'certificate_health') {
        const expiry = parts.find(
            (part) => part.key === 'causes.certificate_expiry',
        );
        const days = expiry ? numericParam(expiry, 'days') : null;
        if (days !== null && days >= 0) {
            return t('site_sections.expires_in_days', {
                count: days,
                formattedCount: format.number(days),
            });
        }
    }
    if (check.key === 'domain') {
        const part = parts.find((item) => item.key in domainDateKeys);
        const date = part?.params.date;
        if (part && typeof date === 'string') {
            return t(domainDateKeys[part.key]!, {
                date: format.calendarDate(date),
            });
        }
    }
    const state = checkState(check);
    if (check.key === 'uptime' && (state === 'ok' || state === 'down')) {
        return t(state === 'ok' ? 'Up' : 'Down');
    }
    if (check.presentation?.history_label) {
        return format.presentationPart(check.presentation.history_label);
    }
    return t(statusLabels[state]);
}

/** Compact one-line status fact for the site rail. */
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
        return checkState(check) === 'ok'
            ? t('site_sections.snoozed')
            : `${t('site_sections.snoozed')} · ${base}`;
    }
    return base;
}
