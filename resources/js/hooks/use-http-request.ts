import { HttpError } from '@inertiajs/core';
import type {
    Errors,
    FormDataType,
    Method,
    UrlMethodPair,
    UseHttpSubmitOptions,
} from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
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
    const { get, post, put, patch, delete: remove } = request;
    const methods = useMemo(() => {
        const inertia = { get, post, put, patch, delete: remove };
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
            submit: (
                route: UrlMethodPair,
                options?: Options<TForm, TResponse>,
            ) => send(route.method, route.url, options),
        };
    }, [get, post, put, patch, remove]);
    return { ...request, ...methods };
}
