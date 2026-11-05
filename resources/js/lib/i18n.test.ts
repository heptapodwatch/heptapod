import { expect, test } from 'vitest';
import i18n, { mergeCatalogs } from './i18n';
import { checkTypes } from './check-types';

test('addon catalogs translate check results without duplicate keys', async () => {
    await i18n.changeLanguage('en');
    expect(i18n.t('dns.succeeded')).toBe('DNS records are unchanged.');
    expect(checkTypes.uptime.label).toBe('checks.uptime.label');
    await i18n.changeLanguage('de');
    expect(i18n.t('dns.succeeded')).toBe('DNS-Einträge sind unverändert.');
    expect(i18n.t(checkTypes.uptime.label)).toBe('Erreichbarkeit');
    expect(() =>
        mergeCatalogs(
            { 'dns.succeeded': 'root' },
            { 'dns.succeeded': 'addon' },
        ),
    ).toThrow('Duplicate translation key: dns.succeeded');
});

test('Laravel placeholders, including uppercase variants, use i18next interpolation', async () => {
    await i18n.changeLanguage('en');
    expect(i18n.t('performance.succeeded', { average: '370' })).toBe(
        'Average response time is 370 ms.',
    );
    await i18n.changeLanguage('de');
    expect(
        i18n.t('The :attribute must be at least :length characters.', {
            attribute: 'passwort',
            length: 8,
        }),
    ).toContain('Passwort');
});
