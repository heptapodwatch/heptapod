import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:15
* @route '/api/checks/{check}/disable'
*/
const DisableCheckController = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DisableCheckController.url(args, options),
    method: 'post',
})

DisableCheckController.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/disable',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:15
* @route '/api/checks/{check}/disable'
*/
DisableCheckController.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return DisableCheckController.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:15
* @route '/api/checks/{check}/disable'
*/
DisableCheckController.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DisableCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:15
* @route '/api/checks/{check}/disable'
*/
const DisableCheckControllerForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DisableCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\DisableCheckController::__invoke
* @see app/Http/Controllers/Api/DisableCheckController.php:15
* @route '/api/checks/{check}/disable'
*/
DisableCheckControllerForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: DisableCheckController.url(args, options),
    method: 'post',
})

DisableCheckController.form = DisableCheckControllerForm

export default DisableCheckController