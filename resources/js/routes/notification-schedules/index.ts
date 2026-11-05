import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults, validateParameters } from './../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
export const update = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/notification-schedules/{kind}/{id?}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
update.url = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{kind}', parsedArgs.kind.toString())
            .replace('{id?}', parsedArgs.id?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
update.patch = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationScheduleController::__invoke
* @see app/Http/Controllers/Notifications/NotificationScheduleController.php:18
* @route '/notification-schedules/{kind}/{id?}'
*/
const updateForm = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
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
updateForm.patch = (args: { kind: string | number, id?: string | number } | [kind: string | number, id: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const notificationSchedules = {
    update: Object.assign(update, update),
}

export default notificationSchedules