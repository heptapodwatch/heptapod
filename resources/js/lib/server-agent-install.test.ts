// @vitest-environment node
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    chmodSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, expect, it } from 'vitest';
import { agentInstallCommands } from '@/lib/server-agent-install';

const install = {
    endpoint: 'https://monitor.example.com/agent/v1/report',
    version: 'v0.19.0',
    release_url:
        'https://github.com/heptapodwatch/heptapod/releases/download/v0.19.0',
    is_release: true,
};
const commands = agentInstallCommands(install);
const roots: string[] = [];

afterEach(() => {
    for (const root of roots.splice(0)) {
        rmSync(root, { recursive: true, force: true });
    }
});

/**
 * Runs a generated command with `curl` serving files from a local release directory (a missing file fails like a
 * 404) and `sudo` only logging that it was reached. Returns whether sudo ran.
 */
function run(command: string, release: Record<string, string>): boolean {
    const root = mkdtempSync(join(tmpdir(), 'agent-install-'));
    roots.push(root);
    const bin = join(root, 'bin');
    const files = join(root, 'release');
    const log = join(root, 'sudo.log');
    execFileSync('mkdir', ['-p', bin, files, join(root, 'tmp')]);
    for (const [name, content] of Object.entries(release)) {
        writeFileSync(join(files, name), content);
    }
    writeFileSync(
        join(bin, 'curl'),
        `#!/bin/sh\nfor arg; do url=$arg; done\ncp "${files}/$(basename "$url")" . 2>/dev/null || exit 22\n`,
    );
    writeFileSync(join(bin, 'sudo'), `#!/bin/sh\necho "$*" >> "${log}"\n`);
    chmodSync(join(bin, 'curl'), 0o755);
    chmodSync(join(bin, 'sudo'), 0o755);
    try {
        execFileSync('sh', ['-c', command], {
            env: {
                PATH: `${bin}:${process.env.PATH ?? ''}`,
                TMPDIR: join(root, 'tmp'),
            },
            stdio: 'ignore',
        });
    } catch {
        // A stopped chain exits non-zero; whether sudo ran is what counts.
    }
    try {
        return readFileSync(log, 'utf8').includes('sh install.sh');
    } catch {
        return false;
    }
}

const script = '#!/bin/sh\necho installer\n';
const sums = (content: string) =>
    `${createHash('sha256').update(content).digest('hex')}  install.sh\n`;

it('reaches sudo only after every download succeeded', () => {
    expect(run(commands.install, { 'install.sh': script })).toBe(true);
    expect(run(commands.install, {})).toBe(false);
});

it('runs the verified install only when install.sh matches its own checksum line', () => {
    expect(
        run(commands.verified, {
            'install.sh': script,
            SHA256SUMS: sums(script),
        }),
    ).toBe(true);
    expect(run(commands.verified, { SHA256SUMS: sums(script) })).toBe(false);
    expect(run(commands.verified, { 'install.sh': script })).toBe(false);
    expect(
        run(commands.verified, {
            'install.sh': script,
            SHA256SUMS: sums('#!/bin/sh\necho tampered\n'),
        }),
    ).toBe(false);
    expect(
        run(commands.verified, {
            'install.sh': script,
            SHA256SUMS: sums(script).replace('install.sh', 'other.sh'),
        }),
    ).toBe(false);
});

it('works in a fresh directory, never in the shell it was pasted into', () => {
    for (const command of [commands.install, commands.verified]) {
        expect(command.startsWith('cd "$(mktemp -d)" \\\n  && ')).toBe(true);
        expect(command).toContain(
            'sudo sh install.sh --version v0.19.0 --endpoint https://monitor.example.com/agent/v1/report',
        );
    }
});
