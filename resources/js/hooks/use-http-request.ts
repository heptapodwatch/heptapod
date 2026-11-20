import {
    HttpError,
    HttpNetworkError,
    HttpResponseError,
} from '@inertiajs/core';
import type {
    Errors,
    FormDataType,
    Method,
    UrlMethodPair,
    UseHttpSubmitOptions,
} from '@inertiajs/core';
import { router, useHttp } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useMemo } from 'react';

/** The server refused the request with a 422; `errors` are its field messages, already set on the form. */
export class HttpValidationError extends HttpError {
    constructor(
        readonly errors: Errors,
        url: string,
    ) {
        super(`Validation failed: ${url}`, 'validation', url);
    }
}

export function httpErrorStatus(error: unknown): number | null {
    return error instanceof HttpResponseError ? error.response.status : null;
}

export function isSessionExpiredError(error: unknown): boolean {
    const status = httpErrorStatus(error);
    return status === 401 || status === 419;
}

function serverPayloadMessage(error: HttpResponseError): string | null {
    const raw = error.response.data;
    if (typeof raw === 'string' && raw.trim().startsWith('{')) {
        try {
            const parsed: unknown = JSON.parse(raw);
            if (
                parsed !== null &&
                typeof parsed === 'object' &&
                'message' in parsed &&
                typeof parsed.message === 'string' &&
                parsed.message.trim() !== ''
            ) {
                return parsed.message;
            }
        } catch {
            return null;
        }
    }
    return null;
}

/**
 * Turn a non-validation `useHttpRequest` error into a user-facing translated message.
 * Prefers the server's localized ExpectedRefusal JSON message for 4xx refusals, then maps common status and network errors.
 */
export function httpErrorMessage(
    error: unknown,
    t: TFunction,
    fallbackKey?: string,
): string {
    if (error instanceof HttpNetworkError) {
        return t('errors.network');
    }
    if (error instanceof HttpResponseError) {
        const status = error.response.status;
        if (status === 401) return t('errors.401.session');
        if (status === 419) return t('errors.419.refreshed');
        const serverMessage = serverPayloadMessage(error);
        if (serverMessage !== null && status >= 400 && status < 500) {
            return serverMessage;
        }
        if (status === 403) return t('errors.403.action');
        if (status === 404) return t('errors.404.action');
        if (status === 409) return t('errors.409.description');
        if (status === 410) return t('errors.410.description');
        if (status === 413) return t('errors.413.upload');
        if (status === 422) return t('errors.422.description');
        if (status === 429) return t('errors.429.description');
        if (status === 502 || status === 503 || status === 504) {
            return t('errors.503.action');
        }
        if (status >= 500) return t('errors.500.action');
    }
    return fallbackKey ? t(fallbackKey) : t('errors.500.action');
}

type Options<TForm, TResponse> = UseHttpSubmitOptions<TResponse, TForm>;
/** Inertia's useHttp form; its type is not exported from @inertiajs/react. */
type InertiaHttp<TForm extends FormDataType<TForm>, TResponse> = ReturnType<
    typeof useHttp<TForm, TResponse>
>;

/** The request methods of `useHttpRequest`; `submit` takes a Wayfinder route. */
export type HttpRequest<TForm extends FormDataType<TForm>, TResponse> = Omit<
    InertiaHttp<TForm, TResponse>,
    'submit'
> & {
    submit: (
        route: UrlMethodPair,
        options?: Options<TForm, TResponse>,
    ) => Promise<TResponse>;
};

/**
 * Inertia's `useHttp`, except that a 422 rejects with `HttpValidationError`. Inertia 3.8 resolves a validation failure
 * to `undefined` (after `onError`), so every `.then` / `await` success path would run on a refused request. The app
 * takes `useHttp` only from here (`scripts/check-ui-primitives.php`).
 */
export function useHttpRequest<
    TForm extends FormDataType<TForm> = Record<string, never>,
    TResponse = unknown,
>(data: TForm = {} as TForm): HttpRequest<TForm, TResponse> {
    const request = useHttp<TForm, TResponse>(data);
    // Inertia keeps its request methods stable across renders; so do these, for effects that depend on them.
    const { get, post, put, patch, delete: remove, query } = request;
    const methods = useMemo(() => {
        const inertia = { get, post, put, patch, delete: remove, query };
        const send = async (
            method: Method,
            url: string,
            options: Options<TForm, TResponse> = {},
        ): Promise<TResponse> => {
            let refused: Errors | null = null;
            const response = await inertia[method](url, {
                ...options,
                onError: (errors) => {
                    refused = errors;
                    options.onError?.(errors);
                },
            });
            if (refused !== null) throw new HttpValidationError(refused, url);
            // Like an Inertia write (app.tsx), a successful one drops every prefetched page, in flight ones included.
            if (method !== 'get' && method !== 'query') router.flushAll();
            return response;
        };
        return {
            get: (url: string, options?: Options<TForm, TResponse>) =>
                send('get', url, options),
            post: (url: string, options?: Options<TForm, TResponse>) =>
                send('post', url, options),
            put: (url: string, options?: Options<TForm, TResponse>) =>
                send('put', url, options),
            patch: (url: string, options?: Options<TForm, TResponse>) =>
                send('patch', url, options),
            delete: (url: string, options?: Options<TForm, TResponse>) =>
                send('delete', url, options),
            query: (url: string, options?: Options<TForm, TResponse>) =>
                send('query', url, options),
            submit: (
                route: UrlMethodPair,
                options?: Options<TForm, TResponse>,
            ) => send(route.method, route.url, options),
        };
    }, [get, post, put, patch, remove, query]);
    return { ...request, ...methods };
}
