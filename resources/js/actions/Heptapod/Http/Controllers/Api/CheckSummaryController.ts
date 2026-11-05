import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
const CheckSummaryController = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckSummaryController.url(args, options),
    method: 'get',
})

CheckSummaryController.definition = {
    methods: ["get","head"],
    url: '/api/monitors/{monitor}/check-summary/{checkType}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
CheckSummaryController.url = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions) => {
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

    return CheckSummaryController.definition.url
            .replace('{monitor}', parsedArgs.monitor.toString())
            .replace('{checkType}', parsedArgs.checkType.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
CheckSummaryController.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CheckSummaryController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
CheckSummaryController.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CheckSummaryController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
const CheckSummaryControllerForm = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckSummaryController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
CheckSummaryControllerForm.get = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckSummaryController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\CheckSummaryController::__invoke
* @see app/Http/Controllers/Api/CheckSummaryController.php:17
* @route '/api/monitors/{monitor}/check-summary/{checkType}'
*/
CheckSummaryControllerForm.head = (args: { monitor: number | { id: number }, checkType: string | number } | [monitor: number | { id: number }, checkType: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: CheckSummaryController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

CheckSummaryController.form = CheckSummaryControllerForm

export default CheckSummaryController