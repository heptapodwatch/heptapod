import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
export const data = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(args, options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/notifications/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
data.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { client: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
    }

    return data.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
data.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
data.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
const dataForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
dataForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Notifications\ClientNotificationSettingsController::data
* @see app/Http/Controllers/Notifications/ClientNotificationSettingsController.php:31
* @route '/clients/{client}/notifications/data'
*/
dataForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

data.form = dataForm

const notifications = {
    data: Object.assign(data, data),
}

export default notifications