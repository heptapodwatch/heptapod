import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::store
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
export const store = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: store.url(args, options),
    method: 'put',
})

store.definition = {
    methods: ["put"],
    url: '/notification-channels/{channel}/site-mutes/{site}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::store
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
store.url = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            channel: args[0],
            site: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        channel: typeof args.channel === 'object'
        ? args.channel.id
        : args.channel,
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return store.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::store
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
store.put = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: store.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::store
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
const storeForm = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::store
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
storeForm.put = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::destroy
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:24
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
export const destroy = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/notification-channels/{channel}/site-mutes/{site}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::destroy
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:24
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
destroy.url = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            channel: args[0],
            site: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        channel: typeof args.channel === 'object'
        ? args.channel.id
        : args.channel,
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
    }

    return destroy.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::destroy
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:24
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
destroy.delete = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::destroy
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:24
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
const destroyForm = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::destroy
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:24
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
destroyForm.delete = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const siteMutes = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default siteMutes