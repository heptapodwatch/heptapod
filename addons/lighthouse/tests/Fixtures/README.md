# Recorded Lighthouse reports

Both files are full Lighthouse 13.5.0 LHR JSON reports for https://example.com/. They contain no configured secret headers and are not trimmed. Tests mutate copies to exercise thresholds, absent metrics, errors, and secret scrubbing.

- `mobile.json`: captured on 2026-10-01 at 18:48:18.219 UTC by the ARM64 spike image `heptapod-spike-lighthouse:arm64` (Node 24, Debian Chromium 154). Source: the Lighthouse ARM spike's `lhr-arm64.json`.
- `desktop.json`: captured on 2026-10-02 at 00:00:16.862 UTC with the Lighthouse image `heptapod-lighthouse:r3-arm64`. Source: `lhr-desktop-example.com.json` from a fixture capture.

The `interactive` audit is present in both reports (787.2195000000002 ms mobile, 300 ms desktop). PWA is absent. An absent audit produces null rather than zero.
