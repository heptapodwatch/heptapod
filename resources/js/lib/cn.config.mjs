/**
 * The app's named type tokens (resources/css/app.css) for class merging: without them, `text-cell` would be read as a
 * colour and dropped next to `text-muted-foreground`. `cn build` (the Vite plugin) compiles them into cn-tables.ts.
 */
export default {
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
};
