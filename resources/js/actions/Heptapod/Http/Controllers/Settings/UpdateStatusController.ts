import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/updates/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
const showForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
*/
showForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\UpdateStatusController::show
* @see app/Http/Controllers/Settings/UpdateStatusController.php:35
* @route '/settings/updates/data'
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

const UpdateStatusController = { edit, show, update }

export default UpdateStatusController