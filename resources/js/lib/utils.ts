import type { InertiaLinkProps } from '@inertiajs/react';
import { createCn } from 'cn/engine';
import tables from '@/lib/cn-tables';

// Tables compiled at build time from cn.config.mjs, so the first page load skips compiling the merge config.
export const cn = createCn(tables);

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
