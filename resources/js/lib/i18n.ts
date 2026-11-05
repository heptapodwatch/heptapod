import { router } from '@inertiajs/react';
import type { GlobalEvent } from '@inertiajs/core';
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
export type Locale = 'en' | 'de';

const coreCatalogs = import.meta.glob('/lang/*/*.json', {
    eager: true,
    import: 'default',
}) as Record<string, Record<string, string>>;

const addonCatalogs = import.meta.glob('/addons/*/lang/*.json', {
    eager: true,
    import: 'default',
}) as Record<string, Record<string, string>>;

export function mergeCatalogs(
    root: Record<string, string>,
    addon: Record<string, string>,
): Record<string, string> {
    for (const key of Object.keys(addon)) {
        if (Object.hasOwn(root, key)) {
            throw new Error(`Duplicate translation key: ${key}`);
        }
    }
    return { ...root, ...addon };
}

function catalog(locale: Locale): Record<string, string> {
    let entries: Record<string, string> = {};
    for (const [path, area] of Object.entries(coreCatalogs).sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        if (path.startsWith(`/lang/${locale}/`)) {
            entries = mergeCatalogs(entries, area);
        }
    }
    for (const [path, addon] of Object.entries(addonCatalogs).sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        if (path.endsWith(`/${locale}.json`)) {
            entries = mergeCatalogs(entries, addon);
        }
    }
    return Object.fromEntries(
        Object.entries(entries).map(([key, value]) => [
            key,
            value.replace(
                /:([A-Za-z][A-Za-z0-9_]*)/g,
                (_, placeholder: string) => {
                    const name = placeholder.toLowerCase();
                    const format =
                        placeholder === placeholder.toUpperCase()
                            ? ', uppercase'
                            : placeholder[0] === placeholder[0]?.toUpperCase()
                              ? ', capitalize'
                              : '';
                    return `{{${name}${format}}}`;
                },
            ),
        ]),
    );
}

void i18n.use(initReactI18next).init({
    resources: {
        en: { translation: catalog('en') },
        de: { translation: catalog('de') },
    },
    lng: typeof document === 'undefined' ? 'en' : document.documentElement.lang,
    fallbackLng: 'en',
    keySeparator: false,
    nsSeparator: false,
    interpolation: { escapeValue: false },
});

const formatter = i18n.services.formatter;
if (!formatter)
    throw new Error('i18next formatter is required for Laravel placeholders.');
formatter.add('uppercase', (value: unknown, language) =>
    String(value).toLocaleUpperCase(language),
);
formatter.add(
    'capitalize',
    (value: unknown, language) =>
        String(value).charAt(0).toLocaleUpperCase(language) +
        String(value).slice(1),
);

function synchronizeLocale(locale: Locale): void {
    document.documentElement.lang = locale;
    if (i18n.language !== locale) {
        void i18n.changeLanguage(locale);
    }
}

function synchronizePageLocale(
    event: GlobalEvent<'navigate'> | GlobalEvent<'success'>,
): void {
    synchronizeLocale(event.detail.page.props.locale);
}

// Navigate includes history; success also includes applied replacement and partial responses.
const removeNavigateListener = router.on('navigate', synchronizePageLocale);
const removeSuccessListener = router.on('success', synchronizePageLocale);
import.meta.hot?.dispose(() => {
    removeNavigateListener();
    removeSuccessListener();
});

export function setLocale(locale: Locale): void {
    document.cookie = `locale=${locale};path=/;max-age=31536000;SameSite=Lax`;
    synchronizeLocale(locale);
}

export default i18n;
