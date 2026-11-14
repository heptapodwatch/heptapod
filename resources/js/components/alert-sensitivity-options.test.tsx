import { expect, it } from 'vitest';
import {
    exampleDurations,
    exampleMessages,
} from '@/components/alert-sensitivity-options';

// Strict, Balanced, Relaxed, Patient and Custom 60 min / 10 checks.
const timings = [
    [2, 1, 'standard'],
    [5, 3, 'standard'],
    [30, 3, 'daily'],
    [60, 3, 'daily'],
    [60, 10, 'standard'],
] as const;

it('derives timing examples that show a quiet outage, an alerted one and distinct message lists', () => {
    for (const [minutes, checks, reminders] of timings) {
        const lists = exampleDurations(minutes, reminders).map((duration) =>
            exampleMessages(duration, minutes, checks, reminders)
                .map((message) => message.kind)
                .join(','),
        );
        expect(lists[0], `${minutes}/${checks}`).toBe('');
        expect(lists[1], `${minutes}/${checks}`).toBe('alert,recovery');
        // "After 1 h, 4 h, then daily" shows its first reminder; daily reminders start after 24 h and add no example.
        expect(lists.slice(2), `${minutes}/${checks}`).toEqual(
            reminders === 'standard' ? ['alert,reminder,recovery'] : [],
        );
        expect(new Set(lists).size).toBe(lists.length);
    }
});
