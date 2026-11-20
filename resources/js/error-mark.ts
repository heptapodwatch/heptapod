// Standalone zero-DB entrypoint for resources/views/errors/error.blade.php.
// Pure static script: no React, no Inertia, no API calls.
import { attachErrorMark } from '@/lib/error-mark';

for (const host of document.querySelectorAll<HTMLElement>(
    '[data-error-mark]',
)) {
    const svg = host.querySelector<SVGSVGElement>('svg.hp-mark');
    const status = Number(host.dataset.errorMark);
    if (svg && Number.isFinite(status)) {
        attachErrorMark(svg, status, host);
    }
}
