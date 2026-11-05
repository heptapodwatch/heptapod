import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
const NotificationChannelTestController = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NotificationChannelTestController.url(args, options),
    method: 'post',
})

NotificationChannelTestController.definition = {
    methods: ["post"],
    url: '/notification-channels/{channel}/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
NotificationChannelTestController.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return NotificationChannelTestController.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
NotificationChannelTestController.post = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NotificationChannelTestController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
const NotificationChannelTestControllerForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationChannelTestController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
NotificationChannelTestControllerForm.post = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: NotificationChannelTestController.url(args, options),
    method: 'post',
})

NotificationChannelTestController.form = NotificationChannelTestControllerForm

export default NotificationChannelTestController