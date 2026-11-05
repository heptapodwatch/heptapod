// WCAG 2.2 AA: text >= 4.5:1 (1.4.3), status shapes >= 3:1 (1.4.11).
// Read the application tokens, not a parallel palette. Run: node scripts/contrast.mjs
import { readFileSync } from 'node:fs';

const css = readFileSync(
    new URL('../resources/css/app.css', import.meta.url),
    'utf8',
);

function tokens(theme) {
    const selector = theme === 'light' ? ':root' : '\\.dark';
    // A theme may be split over several blocks (motion tokens, colour tokens); read them all.
    const blocks = [
        ...css.matchAll(new RegExp(`^${selector} \\{([^}]*)\\}`, 'gm')),
    ];
    if (blocks.length === 0) throw new Error(`Missing ${theme} theme`);
    return Object.fromEntries(
        blocks.flatMap((block) =>
            [...block[1].matchAll(/--([\w-]+):\s*(#[0-9a-f]{6})\s*;/gi)].map(
                (match) => [match[1], match[2]],
            ),
        ),
    );
}

function channels(hex) {
    if (!/^#[0-9a-f]{6}$/i.test(hex))
        throw new Error(`Expected a hex colour, got ${hex}`);
    return [1, 3, 5].map(
        (offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255,
    );
}

function luminance(colour) {
    const [red, green, blue] = colour.map((channel) =>
        channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4,
    );
    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function ratio(foreground, background) {
    const [lighter, darker] = [
        luminance(foreground),
        luminance(background),
    ].sort((a, b) => b - a);
    return (lighter + 0.05) / (darker + 0.05);
}

const surfaces = ['background', 'card', 'secondary'];
const textChecks = [
    ['Text', 'foreground'],
    ['Secondary text', 'foreground-secondary'],
    ['Muted text', 'muted-foreground'],
    ['Meta text', 'foreground-faint'],
    ['Status text · OK', 'status-ok'],
    ['Status text · Warning', 'status-warn'],
    ['Status text · Failed / Down', 'status-down'],
    ['Status text · Maintenance', 'status-info'],
    ['Status text · Check error', 'status-error'],
];
const ringChecks = [
    ['Ring · OK', 'status-ok'],
    ['Ring · Warning', 'status-warn'],
    ['Ring · Failed / Down', 'status-down'],
    ['Ring · Maintenance', 'status-info'],
    ['Ring · Check error', 'status-error'],
    ['Ring · Pending', 'status-pending'],
    ['Ring · Paused', 'status-paused'],
    ['Ring · Off / Unavailable', 'status-disabled'],
    ['Ring · Running', 'muted-foreground'],
];
let failures = 0;
function report(value, minimum) {
    if (value < minimum) failures++;
    return `${value.toFixed(2)}${value < minimum ? ' FAIL' : ''}`;
}

for (const theme of ['dark', 'light']) {
    const palette = tokens(theme);
    const colour = (name) => channels(palette[name]);
    console.log(
        `\n### ${theme}\n\n| Pair | Min | bg | surface | raised |\n|---|---|---|---|---|`,
    );
    for (const { checks, minimum } of [
        { checks: textChecks, minimum: 4.5 },
        { checks: ringChecks, minimum: 3 },
    ]) {
        for (const [label, foreground] of checks) {
            const cells = surfaces.map((surface) =>
                report(ratio(colour(foreground), colour(surface)), minimum),
            );
            console.log(
                `| ${label} (\`--${foreground}\`) | ${minimum} | ${cells.join(' | ')} |`,
            );
        }
    }
    for (const [label, foreground, background] of [
        ['Primary button', 'primary-foreground', 'primary'],
        ['Down badge', 'status-down-foreground', 'status-down'],
        ['Destructive button', 'destructive-foreground', 'destructive'],
    ]) {
        console.log(
            `| ${label} | 4.5 | ${report(ratio(colour(foreground), colour(background)), 4.5)} | | |`,
        );
    }
    // Unselected radio and checkbox borders are the only sign of the control (1.4.11 non-text contrast). They also sit
    // in sheets (popover) and on the changed-row tint of the channel grid (accent; border in dark).
    for (const surface of [
        'background',
        'card',
        'popover',
        'muted',
        theme === 'dark' ? 'border' : 'accent',
    ]) {
        console.log(
            `| Control border · radio, checkbox on \`--${surface}\` | 3 | ${report(ratio(colour('control-border'), colour(surface)), 3)} | | |`,
        );
    }
    // Tinted badges use 6% status ink over the card, matching status-rings.css.
    for (const status of ['warn', 'down', 'info', 'error']) {
        const ink = colour(`status-${status}`);
        const tinted = colour('card').map(
            (channel, index) => 0.94 * channel + 0.06 * ink[index],
        );
        console.log(
            `| Tinted badge · ${status} | 4.5 | ${report(ratio(ink, tinted), 4.5)} | | |`,
        );
    }
}
console.log(
    failures ? `\n${failures} pair(s) below AA.` : '\nAll pairs meet AA.',
);
process.exitCode = failures ? 1 : 0;
