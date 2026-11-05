import { expect, test } from 'vitest';
import {
    checkTabPageFixture,
    sites,
    sitesPageFixture,
    siteListFixtures,
    dashboardEvents,
    addSitePage,
} from './design';
import { checkIcon } from '@/lib/check-types';

test('new pending site stays coherent in site list snapshots', () => {
    const pending = sites[19]!;
    expect(pending.state).toBe('pending');
    expect(pending.checked_at).toBeNull();
    expect(pending.uptime).toBeNull();
    expect(pending.response).toEqual([]);
    expect(
        pending.checks.every(
            (check) =>
                check.result === 'pending' &&
                check.checked_at === null &&
                check.open_incident_id === null,
        ),
    ).toBe(true);
    expect(dashboardEvents.some((event) => event.site_id === pending.id)).toBe(
        false,
    );
    expect(
        sitesPageFixture('populated').sites?.find(
            (site) => site.id === pending.id,
        ),
    ).toEqual(pending);
    expect(
        siteListFixtures('populated').find((site) => site.id === pending.id),
    ).toEqual(pending);
});

test('check fixtures use the backend icon vocabulary for each rendered check', () => {
    for (const check of [
        ...sites.flatMap((site) => site.checks),
        ...addSitePage.checks,
    ]) {
        expect(
            checkIcon('icon_id' in check ? check.icon_id : check.icon),
        ).toBeDefined();
    }
});

test('check tab settings use their generated schema keys, not presentation labels', () => {
    const site = sites[0]!;
    for (const check of site.checks) {
        const payload = checkTabPageFixture(site, check);
        expect(Object.keys(payload.check_settings.settings).sort()).toEqual(
            Object.keys(payload.check_settings.config_schema.fields).sort(),
        );
    }
    const uptime = checkTabPageFixture(
        site,
        site.checks.find((check) => check.key === 'uptime')!,
    );
    expect(uptime.check_settings.settings).toMatchObject({
        http_verb: 'GET',
        timeout: 10,
    });
});
