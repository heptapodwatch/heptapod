import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useFormat } from '@/hooks/use-format';

function remainingSecondsUntil(until: string | null, nowMs: number): number {
    if (!until) return 0;
    const target = Date.parse(until);
    if (Number.isNaN(target)) return 0;
    return Math.max(0, Math.ceil((target - nowMs) / 1000));
}

/**
 * Tracks a timed server cooldown and flips to inactive automatically when the deadline passes without a reload.
 */
export function useCooldown(until: string | null): {
    active: boolean;
    remainingSeconds: number;
} {
    const [nowMs, setNowMs] = useState(() => Date.now());
    const remainingSeconds = remainingSecondsUntil(until, nowMs);
    const active = remainingSeconds > 0;

    useEffect(() => {
        if (!until) return;
        const target = Date.parse(until);
        if (Number.isNaN(target) || target <= Date.now()) {
            setNowMs(Date.now());
            return;
        }
        setNowMs(Date.now());
        const timer = window.setInterval(() => {
            const current = Date.now();
            setNowMs(current);
            if (current >= target) {
                window.clearInterval(timer);
            }
        }, 1000);
        return () => window.clearInterval(timer);
    }, [until]);

    return { active, remainingSeconds };
}

/** One-line cooldown explanation rendered directly below a disabled control ("Can run again at 14:07 · in 4 min"). */
export function CooldownReason({
    until,
    messageKey = 'errors.check_cooldown_reason',
}: {
    until: string | null;
    messageKey?:
        | 'errors.check_cooldown_reason'
        | 'errors.backup.cooldown_reason';
}) {
    const { t } = useTranslation();
    const format = useFormat();
    const { active, remainingSeconds } = useCooldown(until);

    if (!active || !until) return null;

    const wait =
        remainingSeconds < 60
            ? t('errors.check_cooldown_wait_seconds', {
                  count: remainingSeconds,
              })
            : t('errors.check_cooldown_wait_minutes', {
                  count: Math.ceil(remainingSeconds / 60),
              });

    return (
        <p role="status" className="text-xs text-muted-foreground">
            {t(messageKey, {
                time: format.time(until),
                wait,
            })}
        </p>
    );
}
