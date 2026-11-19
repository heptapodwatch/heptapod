/**
 * The cache tag of every prefetched page. A successful write drops all of them (`invalidateCacheTags` for Inertia visits
 * in app.tsx, `useHttpRequest` for HTTP requests), so a page the user changed never comes back from the prefetch cache.
 */
export const pageCacheTag = 'page';
