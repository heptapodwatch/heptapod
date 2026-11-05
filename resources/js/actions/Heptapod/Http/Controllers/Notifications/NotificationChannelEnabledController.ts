import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
const NotificationChannelEnabledController = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: NotificationChannelEnabledController.url(args, options),
    method: 'patch',
})

NotificationChannelEnabledController.definition = {
    methods: ["patch"],
    url: '/notification-channels/{channel}/enabled',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
NotificationChannelEnabledController.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return NotificationChannelEnabledController.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
NotificationChannelEnabledController.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: NotificationChannelEnabledController.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
const NotificationChannelEnabledControllerForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationChannelEnabledController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
NotificationChannelEnabledControllerForm.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationChannelEnabledController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

NotificationChannelEnabledController.form = NotificationChannelEnabledControllerForm

export default NotificationChannelEnabledController