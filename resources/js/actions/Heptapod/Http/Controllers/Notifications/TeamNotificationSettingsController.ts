import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/team',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::edit
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
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
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/team/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
const showForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
showForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::show
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
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

const TeamNotificationSettingsController = { edit, show }

export default TeamNotificationSettingsController