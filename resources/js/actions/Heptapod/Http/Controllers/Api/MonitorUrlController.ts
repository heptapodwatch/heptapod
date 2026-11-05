import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
const MonitorUrlController = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MonitorUrlController.url(args, options),
    method: 'get',
})

MonitorUrlController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/url/{url}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
MonitorUrlController.url = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { url: args }
    }

    if (Array.isArray(args)) {
        args = {
            url: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        url: args.url,
    }

    return MonitorUrlController.definition.url
            .replace('{url}', parsedArgs.url.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
MonitorUrlController.get = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MonitorUrlController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
MonitorUrlController.head = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: MonitorUrlController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
const MonitorUrlControllerForm = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MonitorUrlController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
MonitorUrlControllerForm.get = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MonitorUrlController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\MonitorUrlController::__invoke
* @see app/Http/Controllers/Api/MonitorUrlController.php:16
* @route '/api/monitors/url/{url}'
*/
MonitorUrlControllerForm.head = (args: { url: string | number } | [url: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MonitorUrlController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

MonitorUrlController.form = MonitorUrlControllerForm

export default MonitorUrlController