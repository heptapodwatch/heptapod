import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
const UptimeMetricsController = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UptimeMetricsController.url(args, options),
    method: 'get',
})

UptimeMetricsController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/http-uptime-metrics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
UptimeMetricsController.url = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return UptimeMetricsController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
UptimeMetricsController.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: UptimeMetricsController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
UptimeMetricsController.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: UptimeMetricsController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
const UptimeMetricsControllerForm = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeMetricsController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
UptimeMetricsControllerForm.get = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeMetricsController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\UptimeMetricsController::__invoke
* @see app/Http/Controllers/Api/UptimeMetricsController.php:21
* @route '/api/monitors/{monitor}/http-uptime-metrics'
*/
UptimeMetricsControllerForm.head = (args: { monitor: number | { id: number } } | [monitor: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: UptimeMetricsController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

UptimeMetricsController.form = UptimeMetricsControllerForm

export default UptimeMetricsController