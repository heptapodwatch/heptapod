import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
const UptimeController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UptimeController.url(args, options),
    method: 'get',
})

UptimeController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/uptime',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
UptimeController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return UptimeController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
UptimeController.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UptimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
UptimeController.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: UptimeController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
const UptimeControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
UptimeControllerForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeController::__invoke
* @see app/Http/Controllers/Api/UptimeController.php:24
* @route '/api/monitors/{monitor}/uptime'
*/
UptimeControllerForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

UptimeController.form = UptimeControllerForm

export default UptimeController