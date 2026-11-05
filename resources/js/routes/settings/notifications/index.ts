import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
import team4b9005 from './team'
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
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\OwnNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/OwnNotificationSettingsController.php:26
* @route '/settings/notifications/data'
*/
dataForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

data.form = dataForm

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
export const team = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: team.url(options),
    method: 'get',
})

team.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/team',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
team.url = (options?: RouteQueryOptions) => {
    return team.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
team.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: team.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
team.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: team.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
const teamForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: team.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
teamForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: team.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::team
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:21
* @route '/settings/notifications/team'
*/
teamForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: team.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

team.form = teamForm

const notifications = {
    edit: Object.assign(edit, edit),
    data: Object.assign(data, data),
    team: Object.assign(team, team4b9005),
}

export default notifications