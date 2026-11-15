// One module per locale, so a page loads only the catalog it shows (resources/js/lib/i18n.ts).
export const core = import.meta.glob<Record<string, string>>(
    '/lang/en/*.json',
    {
        eager: true,
        import: 'default',
    },
);

export const addons = import.meta.glob<Record<string, string>>(
    '/addons/*/lang/en.json',
    { eager: true, import: 'default' },
);
