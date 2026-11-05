import { useCallback } from 'react';

export type GetInitialsFn = (fullName: string) => string;

function getInitial(name: string): string {
    return Array.from(name)[0] ?? '';
}

export function useInitials(): GetInitialsFn {
    return useCallback((fullName: string): string => {
        const names = fullName.trim().split(/\s+/u).filter(Boolean);

        const firstName = names[0];
        const lastName = names.at(-1);

        if (firstName === undefined || lastName === undefined) {
            return '';
        }

        if (names.length === 1) {
            return getInitial(firstName).toUpperCase();
        }

        const firstInitial = getInitial(firstName);
        const lastInitial = getInitial(lastName);

        return `${firstInitial}${lastInitial}`.toUpperCase();
    }, []);
}
