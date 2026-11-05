import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
const NotificationPreferencesController = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: NotificationPreferencesController.url(args, options),
    method: 'put',
})

NotificationPreferencesController.definition = {
    methods: ["put"],
    url: '/notification-channels/{channel}/preferences',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
NotificationPreferencesController.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { channel: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { channel: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            channel: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        channel: typeof args.channel === 'object'
        ? args.channel.id
        : args.channel,
    }

    return NotificationPreferencesController.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
NotificationPreferencesController.put = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: NotificationPreferencesController.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
const NotificationPreferencesControllerForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationPreferencesController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
NotificationPreferencesControllerForm.put = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationPreferencesController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

NotificationPreferencesController.form = NotificationPreferencesControllerForm

export default NotificationPreferencesController