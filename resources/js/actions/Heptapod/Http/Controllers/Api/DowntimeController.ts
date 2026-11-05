import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
const DowntimeController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DowntimeController.url(args, options),
    method: 'get',
})

DowntimeController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/downtime',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
DowntimeController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { monitor: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { monitor: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
    }

    return DowntimeController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
DowntimeController.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DowntimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
DowntimeController.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: DowntimeController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
const DowntimeControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: DowntimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
DowntimeControllerForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: DowntimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\DowntimeController::__invoke
* @see app/Http/Controllers/Api/DowntimeController.php:19
* @route '/api/monitors/{monitor}/downtime'
*/
DowntimeControllerForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: DowntimeController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

DowntimeController.form = DowntimeControllerForm

export default DowntimeController