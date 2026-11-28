import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
const SnoozeCheckController = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SnoozeCheckController.url(args, options),
    method: 'post',
})

SnoozeCheckController.definition = {
    methods: ["post"],
    url: '/api/checks/{check}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
SnoozeCheckController.url = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return SnoozeCheckController.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
SnoozeCheckController.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SnoozeCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
const SnoozeCheckControllerForm = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SnoozeCheckController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCheckController::__invoke
* @see app/Http/Controllers/Api/SnoozeCheckController.php:23
* @route '/api/checks/{check}/snooze'
*/
SnoozeCheckControllerForm.post = (args: { check: number | { id: number } } | [check: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SnoozeCheckController.url(args, options),
    method: 'post',
})

SnoozeCheckController.form = SnoozeCheckControllerForm

export default SnoozeCheckController