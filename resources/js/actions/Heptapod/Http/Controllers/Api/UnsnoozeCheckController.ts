import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:19
* @route '/api/checks/{check}/unsnooze'
*/
const UnsnoozeCheckController = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UnsnoozeCheckController.url(args, options),
    method: 'post',
})

UnsnoozeCheckController.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/unsnooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:19
* @route '/api/checks/{check}/unsnooze'
*/
UnsnoozeCheckController.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return UnsnoozeCheckController.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:19
* @route '/api/checks/{check}/unsnooze'
*/
UnsnoozeCheckController.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UnsnoozeCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:19
* @route '/api/checks/{check}/unsnooze'
*/
const UnsnoozeCheckControllerForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UnsnoozeCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCheckController.php:19
* @route '/api/checks/{check}/unsnooze'
*/
UnsnoozeCheckControllerForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UnsnoozeCheckController.url(args, options),
    method: 'post',
})

UnsnoozeCheckController.form = UnsnoozeCheckControllerForm

export default UnsnoozeCheckController