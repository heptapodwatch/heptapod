import { globSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Plugin } from 'vite';

const registryId = 'virtual:language-catalogs';
const catalogPath = 'resources/js/lib/catalogs';

// Keep one lazy, preloaded chunk per language without a hand-maintained module for each locale.
export function languageCatalogs(): Plugin {
    let root: string;
    return {
        name: 'heptapod-language-catalogs',
        configResolved(config) {
            root = config.root;
        },
        resolveId(id) {
            if (id === registryId) return `\0${registryId}`;
            if (id.startsWith(`/${catalogPath}/`))
                return resolve(root, `.${id}`);
        },
        load(id) {
            if (id === `\0${registryId}`) {
                const locales = new Set(
                    globSync('lang/*/*.json', { cwd: root })
                        .sort()
                        .map((file) => file.split('/')[1]),
                );
                return `export default {${[...locales]
                    .map(
                        (locale) =>
                            `${JSON.stringify(locale)}: () => import(${JSON.stringify(`/${catalogPath}/${locale}.ts`)})`,
                    )
                    .join(',')}};`;
            }
            if (id.startsWith(resolve(root, catalogPath) + '/')) {
                const locale = id.slice(id.lastIndexOf('/') + 1, -3);
                return `export const core = import.meta.glob('/lang/${locale}/*.json', { eager: true, import: 'default' });
                    export const addons = import.meta.glob('/addons/*/lang/${locale}.json', { eager: true, import: 'default' });`;
            }
        },
    };
}
