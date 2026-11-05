import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:19
* @route '/settings/notifications'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
const showForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
showForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
showForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const OwnNotificationSettingsController = { edit, show }

export default OwnNotificationSettingsController