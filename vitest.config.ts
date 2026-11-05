import { fileURLToPath } from 'node:url';
import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['./vitest.setup.ts'],
        fakeTimers: { now: Date.parse('2026-10-21T12:00:00Z') },
        retry: 0,
        sequence: { shuffle: true },
        include: ['resources/js/**/*.test.{ts,tsx}'],
        exclude: [...configDefaults.exclude, '**/.worktrees/**'],
    },
});
