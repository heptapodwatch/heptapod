import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::store
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:24
* @route '/notification-channels'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/notification-channels',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::store
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:24
* @route '/notification-channels'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::store
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:24
* @route '/notification-channels'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::store
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:24
* @route '/notification-channels'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::store
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:24
* @route '/notification-channels'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::update
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:50
* @route '/notification-channels/{channel}'
*/
export const update = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/notification-channels/{channel}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::update
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:50
* @route '/notification-channels/{channel}'
*/
update.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::update
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:50
* @route '/notification-channels/{channel}'
*/
update.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::update
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:50
* @route '/notification-channels/{channel}'
*/
const updateForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::update
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:50
* @route '/notification-channels/{channel}'
*/
updateForm.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::destroy
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:61
* @route '/notification-channels/{channel}'
*/
export const destroy = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/notification-channels/{channel}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::destroy
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:61
* @route '/notification-channels/{channel}'
*/
destroy.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::destroy
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:61
* @route '/notification-channels/{channel}'
*/
destroy.delete = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::destroy
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:61
* @route '/notification-channels/{channel}'
*/
const destroyForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelsController::destroy
* @see app/Http/Controllers/Notifications/NotificationChannelsController.php:61
* @route '/notification-channels/{channel}'
*/
destroyForm.delete = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const NotificationChannelsController = { store, update, destroy }

export default NotificationChannelsController