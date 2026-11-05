import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:19
* @route '/api/checks/{check}/request-run'
*/
const RequestCheckRunController = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestCheckRunController.url(args, options),
    method: 'post',
})

RequestCheckRunController.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/request-run',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:19
* @route '/api/checks/{check}/request-run'
*/
RequestCheckRunController.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { check: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: typeof args.check === 'object'
        ? args.check.id
        : args.check,
    }

    return RequestCheckRunController.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:19
* @route '/api/checks/{check}/request-run'
*/
RequestCheckRunController.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:19
* @route '/api/checks/{check}/request-run'
*/
const RequestCheckRunControllerForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestCheckRunController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\RequestCheckRunController::__invoke
* @see app/Http/Controllers/Api/RequestCheckRunController.php:19
* @route '/api/checks/{check}/request-run'
*/
RequestCheckRunControllerForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RequestCheckRunController.url(args, options),
    method: 'post',
})

RequestCheckRunController.form = RequestCheckRunControllerForm

export default RequestCheckRunController