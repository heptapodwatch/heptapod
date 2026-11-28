import { router } from '@inertiajs/react';
import type { GlobalEvent } from '@inertiajs/core';
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import catalogModules from 'virtual:language-catalogs';

type CatalogModule = Awaited<ReturnType<(typeof catalogModules)[string]>>;

/** Adds the addon's keys to `root` in place; copying the growing catalog once per area cost a quadratic startup. */
export function mergeCatalogs(
    root: Record<string, string>,
    addon: Record<string, string>,
): void {
    for (const key of Object.keys(addon)) {
        if (Object.hasOwn(root, key)) {
            throw new Error(`Duplicate translation key: ${key}`);
        }
    }
    Object.assign(root, addon);
}

function catalog({ core, addons }: CatalogModule): Record<string, string> {
    const entries: Record<string, string> = {};
    for (const [, area] of Object.entries(core).sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        mergeCatalogs(entries, area);
    }
    for (const [, addon] of Object.entries(addons).sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        mergeCatalogs(entries, addon);
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

/** Adds a locale's catalog once; the app shell preloads the page locale's (resources/views/app.blade.php). */
export async function loadLocale(locale: string): Promise<void> {
    if (i18n.hasResourceBundle(locale, 'translation')) return;
    const load = Object.hasOwn(catalogModules, locale)
        ? catalogModules[locale]
        : undefined;
    if (!load) throw new Error(`Missing translation catalog for ${locale}`);
    i18n.addResourceBundle(locale, 'translation', catalog(await load()));
}

const initialLocale =
    (typeof document !== 'undefined' && document.documentElement.lang) || 'en';

void i18n.use(initReactI18next).init({
    resources: {},
    lng: initialLocale,
    fallbackLng: 'en',
    keySeparator: false,
    nsSeparator: false,
    interpolation: { escapeValue: false },
});

/** Resolves once the page locale's catalog is loaded; the app renders after it (resources/js/app.tsx). */
export const i18nReady = loadLocale(initialLocale);

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

function synchronizeLocale(locale: string): void {
    document.documentElement.lang = locale;
    if (i18n.language !== locale) {
        void loadLocale(locale).then(() => i18n.changeLanguage(locale));
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

export function setLocale(locale: string): void {
    document.cookie = `locale=${locale};path=/;max-age=31536000;SameSite=Lax`;
    synchronizeLocale(locale);
}

export default i18n;
