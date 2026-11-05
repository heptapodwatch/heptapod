export function formatUptimePercentage(
    percentage: number,
    locale: string,
    precision = 2,
): string {
    if (!Number.isFinite(percentage) || percentage < 0 || percentage > 100)
        throw new Error('Uptime percentage must be between 0 and 100.');
    const digits = percentage >= 99.9 && percentage < 100 ? 2 : precision;
    // Any non-zero downtime must remain visible, even below display precision.
    const displayed =
        percentage < 100
            ? Math.min(
                  100 - 10 ** -digits,
                  Math.round(percentage * 10 ** digits) / 10 ** digits,
              )
            : percentage;
    return `${new Intl.NumberFormat(locale, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(displayed)} %`;
}
