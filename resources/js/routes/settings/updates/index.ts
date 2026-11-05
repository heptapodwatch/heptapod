import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/updates',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::edit
* @see app/Http/Controllers/Settings/UpdateStatusController.php:22
* @route '/settings/updates'
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
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
export const status = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/settings/updates/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
status.url = (options?: RouteQueryOptions) => {
    return status.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
status.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
status.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
const statusForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
statusForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::status
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
statusForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

status.form = statusForm

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::update
* @see app/Http/Controllers/Settings/UpdateStatusController.php:43
* @route '/settings/updates'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/updates',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::update
* @see app/Http/Controllers/Settings/UpdateStatusController.php:43
* @route '/settings/updates'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::update
* @see app/Http/Controllers/Settings/UpdateStatusController.php:43
* @route '/settings/updates'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::update
* @see app/Http/Controllers/Settings/UpdateStatusController.php:43
* @route '/settings/updates'
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
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::update
* @see app/Http/Controllers/Settings/UpdateStatusController.php:43
* @route '/settings/updates'
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

const updates = {
    edit: Object.assign(edit, edit),
    status: Object.assign(status, status),
    update: Object.assign(update, update),
}

export default updates