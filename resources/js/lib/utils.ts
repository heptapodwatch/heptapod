import type { InertiaLinkProps } from '@inertiajs/react';
import { createCn } from 'cn/config';

/**
 * Class merging that knows the app's named type tokens (resources/css/app.css): without them, `text-cell` would be read as a
 * colour and dropped next to `text-muted-foreground`.
 */
export const cn = createCn({
    extend: {
        theme: {
            text: [
                'page-title',
                'headline',
                'stat',
                'section',
                'cell',
                'label',
                'micro',
                'mark',
            ],
        },
    },
});

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
