import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/backups/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\BackupController::index
* @see app/Http/Controllers/Settings/BackupController.php:16
* @route '/settings/backups/data'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

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

const BackupController = { index, store }

export default BackupController