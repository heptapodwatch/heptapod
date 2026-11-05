import { afterEach, beforeEach, vi } from 'vitest';

// Freeze module initialization as well as each test; hooks may opt into fake timers.
vi.useFakeTimers({ toFake: ['Date'] });
beforeEach(() => vi.useFakeTimers({ toFake: ['Date'] }));
afterEach(() => vi.useRealTimers());

// The app root owns the one TooltipProvider (resources/js/app.tsx); every rendered component gets the same one here.
vi.mock('@testing-library/react', async (importOriginal) => {
    const library =
        await importOriginal<typeof import('@testing-library/react')>();
    const { createElement } = await import('react');
    const { TooltipProvider } = await import('@/components/ui/tooltip');
    const render: typeof library.render = (ui, options) => {
        const Wrapper = options?.wrapper;
        return library.render(ui, {
            ...options,
            wrapper: ({ children }) =>
                createElement(
                    TooltipProvider,
                    null,
                    Wrapper ? createElement(Wrapper, null, children) : children,
                ),
        } as typeof options);
    };
    return { ...library, render };
});
