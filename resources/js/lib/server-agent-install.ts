type Install = Heptapod.Addons.Servers.Data.ServerPageData['install'];

/**
 * The agent's install commands, in the one place that knows the installer's flags (agent/install.sh). The token is
 * never part of a command: the installer asks for it at a hidden "Agent token:" prompt.
 */
export function agentInstallCommands(install: Install) {
    const download = (file: string) =>
        `curl -fsSLO --proto '=https' ${install.release_url}/${file}`;
    const run = `sudo sh install.sh --version ${install.version} --endpoint ${install.endpoint}`;
    return {
        install: [download('install.sh'), run].join('\n'),
        // `&&` stops before sudo when the checksum does not match, without closing the shell it was pasted into.
        verified: [
            download('install.sh'),
            download('SHA256SUMS'),
            `sha256sum --check --ignore-missing SHA256SUMS && ${run}`,
        ].join('\n'),
        attestation:
            'gh attestation verify install.sh --repo heptapodwatch/heptapod --signer-workflow heptapodwatch/heptapod/.github/workflows/release-bundle.yml',
    };
}
