import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
import migrationReport from './migration-report'
/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/notifications/team/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\TeamNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/TeamNotificationSettingsController.php:28
* @route '/settings/notifications/team/data'
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

const team = {
    data: Object.assign(data, data),
    migrationReport: Object.assign(migrationReport, migrationReport),
}

export default team