import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
export const start = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/runs/{run}/start',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
start.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { run: args }
    }

    if (Array.isArray(args)) {
        args = {
            run: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        run: args.run,
    }

    return start.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
start.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
const startForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: start.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\StartOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/StartOhDearImportController.php:21
* @route '/settings/import/ohdear/runs/{run}/start'
*/
startForm.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: start.url(args, options),
    method: 'post',
})

start.form = startForm

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
export const show = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/import/ohdear/runs/{run}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { run: args }
    }

    if (Array.isArray(args)) {
        args = {
            run: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        run: args.run,
    }

    return show.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.get = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
show.head = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
const showForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
showForm.get = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::show
* @see app/Http/Controllers/Settings/ImportOhDearController.php:38
* @route '/settings/import/ohdear/runs/{run}'
*/
showForm.head = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
export const retry = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/runs/{run}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
retry.url = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { run: args }
    }

    if (Array.isArray(args)) {
        args = {
            run: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        run: args.run,
    }

    return retry.definition.url
            .replace('{run}', parsedArgs.run.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
retry.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
const retryForm = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: retry.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\RetryOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/RetryOhDearImportController.php:18
* @route '/settings/import/ohdear/runs/{run}/retry'
*/
retryForm.post = (args: { run: string | number } | [run: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: retry.url(args, options),
    method: 'post',
})

retry.form = retryForm

const runs = {
    start: Object.assign(start, start),
    show: Object.assign(show, show),
    retry: Object.assign(retry, retry),
}

export default runs