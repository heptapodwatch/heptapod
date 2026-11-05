import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
const CheckRunController = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckRunController.url(args, options),
    method: 'get',
})

CheckRunController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/checks/{checkType}/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
CheckRunController.url = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            monitor: args[0],
            checkType: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        monitor: typeof args.monitor === 'object'
        ? args.monitor.id
        : args.monitor,
        checkType: args.checkType,
    }

    return CheckRunController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace('{checkType}', parsedArgs.checkType.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
CheckRunController.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckRunController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
CheckRunController.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CheckRunController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
const CheckRunControllerForm = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
CheckRunControllerForm.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckRunController::__invoke
* @see app/Http/Controllers/Api/CheckRunController.php:19
* @route '/api/monitors/{monitor}/checks/{checkType}/runs'
*/
CheckRunControllerForm.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckRunController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

CheckRunController.form = CheckRunControllerForm

export default CheckRunController