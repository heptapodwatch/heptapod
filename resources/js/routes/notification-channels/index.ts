import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import siteMutes from './site-mutes'
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

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
export const enabled = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: enabled.url(args, options),
    method: 'patch',
})

enabled.definition = {
    methods: ["patch"],
    url: '/notification-channels/{channel}/enabled',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
enabled.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return enabled.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
enabled.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: enabled.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelEnabledController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelEnabledController.php:18
* @route '/notification-channels/{channel}/enabled'
*/
const enabledForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enabled.url(args, {
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
enabledForm.patch = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: enabled.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

enabled.form = enabledForm

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
export const test = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

test.definition = {
    methods: ["post"],
    url: '/notification-channels/{channel}/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
test.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return test.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
test.post = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
const testForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationChannelTestController::__invoke
* @see app/Http/Controllers/Notifications/NotificationChannelTestController.php:18
* @route '/notification-channels/{channel}/test'
*/
testForm.post = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

test.form = testForm

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
export const preferences = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: preferences.url(args, options),
    method: 'put',
})

preferences.definition = {
    methods: ["put"],
    url: '/notification-channels/{channel}/preferences',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
preferences.url = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return preferences.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
preferences.put = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: preferences.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationPreferencesController::__invoke
* @see app/Http/Controllers/Notifications/NotificationPreferencesController.php:17
* @route '/notification-channels/{channel}/preferences'
*/
const preferencesForm = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preferences.url(args, {
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
preferencesForm.put = (args: { channel: number | { id: number } } | [channel: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preferences.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

preferences.form = preferencesForm

const notificationChannels = {
    store: Object.assign(store, store),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    enabled: Object.assign(enabled, enabled),
    test: Object.assign(test, test),
    preferences: Object.assign(preferences, preferences),
    siteMutes: Object.assign(siteMutes, siteMutes),
}

export default notificationChannels