import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const directory = 'public/build';
const manifest = JSON.parse(readFileSync(`${directory}/manifest.json`, 'utf8'));

// First-load JS includes static imports, not every dynamically imported page or locale.
function collectFiles(key, files = new Set()) {
    const chunk = manifest[key];
    if (files.has(chunk.file)) return files;
    files.add(chunk.file);
    for (const dependency of chunk.imports ?? [])
        collectFiles(dependency, files);
    return files;
}

// Rounded headroom over the measured EN/DE build; keep the shell free of charts and the other locale.
const budgets = [
    ['shell', null, 1450],
    ['sites/index', 'resources/js/pages/sites/index.tsx', 1850],
    ['sites/show', 'resources/js/pages/sites/show.tsx', 2050],
    ['settings/profile', 'resources/js/pages/settings/profile.tsx', 1450],
];
for (const locale of ['en', 'de']) {
    for (const [name, page, budgetKiB] of budgets) {
        const files = collectFiles('resources/js/app.tsx');
        const catalog = `resources/js/lib/catalogs/${locale}.ts`;
        // Eager catalogs are already included in the shell's static closure.
        if (manifest[catalog]) collectFiles(catalog, files);
        if (page) collectFiles(page, files);
        let bytes = 0;
        let gzipBytes = 0;
        for (const file of files) {
            if (!file.endsWith('.js')) continue;
            const contents = readFileSync(`${directory}/${file}`);
            bytes += contents.length;
            gzipBytes += gzipSync(contents, { level: 9 }).length;
        }
        const passed = bytes <= budgetKiB * 1024;
        console.log(
            `${passed ? 'PASS' : 'FAIL'} ${locale} ${name}: ${(bytes / 1024).toFixed(1)} KiB raw / ${(gzipBytes / 1024).toFixed(1)} KiB gzip (raw budget ${budgetKiB} KiB)`,
        );
        if (!passed) process.exitCode = 1;
    }
}
