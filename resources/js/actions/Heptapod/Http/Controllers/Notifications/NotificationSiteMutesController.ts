import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::update
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
export const update = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/notification-channels/{channel}/site-mutes/{site}',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::update
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
update.url = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{channel}', parsedArgs.channel.toString())
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::update
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
update.put = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::update
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
const updateForm = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\NotificationSiteMutesController::update
* @see app/Http/Controllers/Notifications/NotificationSiteMutesController.php:19
* @route '/notification-channels/{channel}/site-mutes/{site}'
*/
updateForm.put = (args: { channel: number | { id: number }, site: number | { id: number } } | [channel: number | { id: number }, site: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

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

const NotificationSiteMutesController = { update, destroy }

export default NotificationSiteMutesController