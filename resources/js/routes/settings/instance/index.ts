import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/instance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::edit
* @see app/Http/Controllers/Settings/InstanceController.php:26
* @route '/settings/instance'
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
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/instance/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::data
* @see app/Http/Controllers/Settings/InstanceController.php:33
* @route '/settings/instance/data'
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
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:55
* @route '/settings/instance'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/instance',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:55
* @route '/settings/instance'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:55
* @route '/settings/instance'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:55
* @route '/settings/instance'
*/
const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:55
* @route '/settings/instance'
*/
updateForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const instance = {
    edit: Object.assign(edit, edit),
    data: Object.assign(data, data),
    update: Object.assign(update, update),
}

export default instance