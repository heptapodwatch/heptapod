type Install = Heptapod.Addons.Servers.Data.ServerPageData['install'];

/**
 * The agent's install commands, in the one place that knows the installer's flags (agent/install.sh). The token is
 * never part of a command: the installer asks for it at a hidden "Agent token:" prompt. Each command works in a fresh
 * directory and joins every step with `&&`, so a failed download or checksum stops it before sudo, without closing the
 * shell it was pasted into.
 */
export function agentInstallCommands(install: Install) {
    const download = (file: string) =>
        `curl -fsSLO --proto '=https' ${install.release_url}/${file}`;
    const run = `sudo sh install.sh --version ${install.version} --endpoint ${install.endpoint}`;
    const steps = (...commands: string[]) =>
        ['d=$(mktemp -d)', 'cd "$d"', ...commands].join(' \\\n  && ');
    return {
        install: steps(download('install.sh'), run),
        verified: steps(
            download('install.sh'),
            download('SHA256SUMS'),
            // install.sh's exact record; without one, sha256sum finds nothing to check and fails.
            "grep -x '[0-9a-f]\\{64\\}  install.sh' SHA256SUMS | sha256sum -c -",
            run,
        ),
        attestation:
            'gh attestation verify install.sh --repo heptapodwatch/heptapod --signer-workflow heptapodwatch/heptapod/.github/workflows/release-bundle.yml',
    };
}
