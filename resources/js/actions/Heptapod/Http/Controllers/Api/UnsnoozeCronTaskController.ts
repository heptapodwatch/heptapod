import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:19
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
const UnsnoozeCronTaskController = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UnsnoozeCronTaskController.url(args, options),
    method: 'post',
})

UnsnoozeCronTaskController.definition = {
    methods: ["post"],
    url: '/api/cron-checks/{cronTask}/unsnooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:19
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
UnsnoozeCronTaskController.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { cronTask: args }
    }

    if (Array.isArray(args)) {
        args = {
            cronTask: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        cronTask: args.cronTask,
    }

    return UnsnoozeCronTaskController.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:19
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
UnsnoozeCronTaskController.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UnsnoozeCronTaskController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:19
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
const UnsnoozeCronTaskControllerForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UnsnoozeCronTaskController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\UnsnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/UnsnoozeCronTaskController.php:19
* @route '/api/cron-checks/{cronTask}/unsnooze'
*/
UnsnoozeCronTaskControllerForm.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UnsnoozeCronTaskController.url(args, options),
    method: 'post',
})

UnsnoozeCronTaskController.form = UnsnoozeCronTaskControllerForm

export default UnsnoozeCronTaskController