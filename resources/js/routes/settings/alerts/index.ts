import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/alerts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::edit
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:23
* @route '/settings/alerts'
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
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::update
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:35
* @route '/settings/alerts'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/settings/alerts',
} satisfies RouteDefinition<["put"]>

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::update
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:35
* @route '/settings/alerts'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::update
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:35
* @route '/settings/alerts'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::update
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:35
* @route '/settings/alerts'
*/
const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\AlertSensitivityController::update
* @see app/Http/Controllers/Settings/AlertSensitivityController.php:35
* @route '/settings/alerts'
*/
updateForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const alerts = {
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
}

export default alerts