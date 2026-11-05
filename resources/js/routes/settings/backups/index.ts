import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/backups/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::data
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
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
* @see \Heptapod\Http\Controllers\Settings\BackupController::store
* @see app/Http/Controllers/Settings/BackupController.php:23
* @route '/settings/backups'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/backups',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::store
* @see app/Http/Controllers/Settings/BackupController.php:23
* @route '/settings/backups'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::store
* @see app/Http/Controllers/Settings/BackupController.php:23
* @route '/settings/backups'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::store
* @see app/Http/Controllers/Settings/BackupController.php:23
* @route '/settings/backups'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::store
* @see app/Http/Controllers/Settings/BackupController.php:23
* @route '/settings/backups'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

const backups = {
    data: Object.assign(data, data),
    store: Object.assign(store, store),
}

export default backups