import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:20
* @route '/api/cron-checks/{cronTask}/snooze'
*/
const SnoozeCronTaskController = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SnoozeCronTaskController.url(args, options),
    method: 'post',
})

SnoozeCronTaskController.definition = {
    methods: ["post"],
    url: '/api/cron-checks/{cronTask}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:20
* @route '/api/cron-checks/{cronTask}/snooze'
*/
SnoozeCronTaskController.url = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return SnoozeCronTaskController.definition.url
            .replace('{cronTask}', parsedArgs.cronTask.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:20
* @route '/api/cron-checks/{cronTask}/snooze'
*/
SnoozeCronTaskController.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SnoozeCronTaskController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:20
* @route '/api/cron-checks/{cronTask}/snooze'
*/
const SnoozeCronTaskControllerForm = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SnoozeCronTaskController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\SnoozeCronTaskController::__invoke
* @see app/Http/Controllers/Api/SnoozeCronTaskController.php:20
* @route '/api/cron-checks/{cronTask}/snooze'
*/
SnoozeCronTaskControllerForm.post = (args: { cronTask: string | number } | [cronTask: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SnoozeCronTaskController.url(args, options),
    method: 'post',
})

SnoozeCronTaskController.form = SnoozeCronTaskControllerForm

export default SnoozeCronTaskController