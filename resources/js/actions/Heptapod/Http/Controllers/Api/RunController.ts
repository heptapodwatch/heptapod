import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\RunController::store
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
export const store = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/runs',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\RunController::store
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
store.url = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: args.check,
    }

    return store.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RunController::store
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
store.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::store
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
const storeForm = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::store
* @see app/Http/Controllers/Api/RunController.php:40
* @route '/api/checks/{check}/runs'
*/
storeForm.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
export const show = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/runs/{attemptRef}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
show.url = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { attemptRef: args }
    }

    if (Array.isArray(args)) {
        args = {
            attemptRef: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        attemptRef: args.attemptRef,
    }

    return show.definition.url
            .replace('{attemptRef}', parsedArgs.attemptRef.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
show.get = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
show.head = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
const showForm = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
showForm.get = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\RunController::show
* @see app/Http/Controllers/Api/RunController.php:72
* @route '/api/runs/{attemptRef}'
*/
showForm.head = (args: { attemptRef: string | number } | [attemptRef: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const RunController = { store, show }

export default RunController