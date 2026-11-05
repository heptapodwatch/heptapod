import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/system',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::edit
* @see app/Http/Controllers/Settings/SystemHealthController.php:23
* @route '/settings/system'
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
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/system/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\SystemHealthController::__invoke
* @see app/Http/Controllers/Settings/SystemHealthController.php:30
* @route '/settings/system/data'
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

const system = {
    edit: Object.assign(edit, edit),
    data: Object.assign(data, data),
}

export default system