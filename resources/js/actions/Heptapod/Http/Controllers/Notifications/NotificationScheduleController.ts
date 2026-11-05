import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults, validateParameters } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
const NotificationScheduleController = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: NotificationScheduleController.url(args, options),
    method: 'patch',
})

NotificationScheduleController.definition = {
    methods: ["patch"],
    url: '/notification-schedules/{kind}/{id?}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
NotificationScheduleController.url = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            kind: args[0],
            id: args[1],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "id",
    ])

    const parsedArgs = {
        kind: args.kind,
        id: args.id,
    }

    return NotificationScheduleController.definition.url
            .replace('{kind}', parsedArgs.kind.toString())
            .replace('{id?}', parsedArgs.id?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
NotificationScheduleController.patch = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: NotificationScheduleController.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
const NotificationScheduleControllerForm = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationScheduleController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
NotificationScheduleControllerForm.patch = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationScheduleController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

NotificationScheduleController.form = NotificationScheduleControllerForm

export default NotificationScheduleController