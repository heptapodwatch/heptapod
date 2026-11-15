import type { RenderOptions } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, vi } from 'vitest';

// Freeze module initialization as well as each test; hooks may opt into fake timers.
vi.useFakeTimers({ toFake: ['Date'] });
beforeEach(() => vi.useFakeTimers({ toFake: ['Date'] }));
afterEach(() => vi.useRealTimers());

// The app root owns the one TooltipProvider (resources/js/app.tsx) and the app shell the one FormActionsDock;
// every rendered component gets the same ones here.
vi.mock('@testing-library/react', async (importOriginal) => {
    const library =
        await importOriginal<typeof import('@testing-library/react')>();
    const { createElement } = await import('react');
    const { TooltipProvider } = await import('@/components/ui/tooltip');
    const { FormActionsDock } = await import('@/components/form-section');
    const render = ((ui: ReactNode, options?: RenderOptions) => {
        const Wrapper = options?.wrapper;
        return library.render(ui, {
            ...options,
            wrapper: ({ children }: { children: ReactNode }) =>
                createElement(
                    TooltipProvider,
                    null,
                    createElement(
                        FormActionsDock,
                        null,
                        Wrapper
                            ? createElement(Wrapper, null, children)
                            : children,
                    ),
                ),
        });
    }) as typeof library.render;
    return { ...library, render };
});
