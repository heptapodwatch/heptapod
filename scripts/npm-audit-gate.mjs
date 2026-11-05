// CI gate over `npm audit`: fails on any high or critical advisory, except the ones listed in
// .github/npm-audit-exceptions.json for exactly that package, and only until each exception's expiry date (UTC,
// inclusive). After the expiry the advisory fails the job again by itself. Moderate and low advisories do not fail,
// like `npm audit --audit-level=high`.
// Usage: node scripts/npm-audit-gate.mjs [--audit-json <file>] [--today <YYYY-MM-DD>]
// The two options exist to prove the gate against a saved report or a later date; CI uses neither.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

const failing = new Set(['high', 'critical']);
const args = process.argv.slice(2);
const option = (name) => {
    const index = args.indexOf(name);
    return index === -1 ? null : args[index + 1];
};

const today = option('--today') ?? new Date().toISOString().slice(0, 10);
if (!/^\d{4}-\d{2}-\d{2}$/.test(today)) {
    throw new Error(`--today must be YYYY-MM-DD, got ${today}`);
}

const auditFile = option('--audit-json');
let raw;
if (auditFile) {
    raw = fs.readFileSync(auditFile, 'utf8');
} else {
    // npm audit exits non-zero whenever it finds anything; the JSON report on stdout is what counts.
    const run = spawnSync('npm', ['audit', '--json'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
    if (run.error) {
        throw run.error;
    }
    raw = run.stdout;
}
const report = JSON.parse(raw);
if (
    typeof report.vulnerabilities !== 'object' ||
    report.vulnerabilities === null
) {
    throw new Error(
        `npm audit returned no vulnerabilities object: ${raw.slice(0, 500)}`,
    );
}

const exceptions = JSON.parse(
    fs.readFileSync(
        new URL('../.github/npm-audit-exceptions.json', import.meta.url),
        'utf8',
    ),
).exceptions;
for (const exception of exceptions) {
    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(exception.expires) ||
        !exception.reason ||
        !exception.owner
    ) {
        throw new Error(
            `npm-audit-exceptions.json: ${exception.package} needs expires (YYYY-MM-DD), reason and owner.`,
        );
    }
}
const advisoryId = (url) =>
    url.match(/GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}/)?.[0] ?? url;

// Every advisory object sits under the vulnerable package it was reported for; string entries in `via` only
// propagate another package's advisory, which is evaluated where it sits.
const blocking = [];
const excused = [];
const used = new Set();
for (const [name, vulnerability] of Object.entries(report.vulnerabilities)) {
    for (const advisory of vulnerability.via) {
        if (typeof advisory !== 'object' || !failing.has(advisory.severity)) {
            continue;
        }
        const id = advisoryId(advisory.url);
        const exception = exceptions.find(
            (candidate) =>
                candidate.package === name && candidate.advisories.includes(id),
        );
        const line = `${name} ${id} (${advisory.severity}): ${advisory.title}`;
        if (exception && today <= exception.expires) {
            excused.push(`${line} [excepted until ${exception.expires}]`);
            used.add(`${name} ${id}`);
        } else if (exception) {
            blocking.push(
                `${line} [exception EXPIRED on ${exception.expires}]`,
            );
        } else {
            blocking.push(line);
        }
    }
}

for (const line of excused) {
    console.log(`EXCEPTED ${line}`);
}
for (const exception of exceptions) {
    for (const id of exception.advisories) {
        if (
            !used.has(`${exception.package} ${id}`) &&
            today <= exception.expires
        ) {
            console.log(
                `NOTE exception ${exception.package} ${id} matched no high/critical advisory (it may still be reported below high); remove it once the advisory is gone.`,
            );
        }
    }
}
if (blocking.length > 0) {
    for (const line of blocking) {
        console.error(`BLOCKING ${line}`);
    }
    console.error(
        `npm audit gate failed: ${blocking.length} high/critical advisory(ies) without a valid exception.`,
    );
    process.exit(1);
}
console.log(
    `npm audit gate passed (${today}): no high/critical advisory without a valid exception.`,
);
