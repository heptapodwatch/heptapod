/// <reference types="vite/client" />

declare module 'virtual:language-catalogs' {
    type Areas = Record<string, Record<string, string>>;
    const catalogs: Record<
        string,
        () => Promise<{ core: Areas; addons: Areas }>
    >;
    export default catalogs;
}
