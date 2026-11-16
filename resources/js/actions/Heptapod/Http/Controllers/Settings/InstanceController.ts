import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/instance/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
const showForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
*/
showForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::show
* @see app/Http/Controllers/Settings/InstanceController.php:53
* @route '/settings/instance/data'
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
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:75
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
* @see app/Http/Controllers/Settings/InstanceController.php:75
* @route '/settings/instance'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:75
* @route '/settings/instance'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::update
* @see app/Http/Controllers/Settings/InstanceController.php:75
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
* @see app/Http/Controllers/Settings/InstanceController.php:75
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

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
export const email = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: email.url(options),
    method: 'get',
})

email.definition = {
    methods: ["get","head"],
    url: '/settings/email',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
email.url = (options?: RouteQueryOptions) => {
    return email.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
email.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: email.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
email.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: email.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
const emailForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: email.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
emailForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: email.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::email
* @see app/Http/Controllers/Settings/InstanceController.php:34
* @route '/settings/email'
*/
emailForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: email.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

email.form = emailForm

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::updateMail
* @see app/Http/Controllers/Settings/InstanceController.php:41
* @route '/settings/email'
*/
export const updateMail = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateMail.url(options),
    method: 'patch',
})

updateMail.definition = {
    methods: ["patch"],
    url: '/settings/email',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::updateMail
* @see app/Http/Controllers/Settings/InstanceController.php:41
* @route '/settings/email'
*/
updateMail.url = (options?: RouteQueryOptions) => {
    return updateMail.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::updateMail
* @see app/Http/Controllers/Settings/InstanceController.php:41
* @route '/settings/email'
*/
updateMail.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateMail.url(options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::updateMail
* @see app/Http/Controllers/Settings/InstanceController.php:41
* @route '/settings/email'
*/
const updateMailForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMail.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InstanceController::updateMail
* @see app/Http/Controllers/Settings/InstanceController.php:41
* @route '/settings/email'
*/
updateMailForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMail.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateMail.form = updateMailForm

const InstanceController = { edit, show, update, email, updateMail }

export default InstanceController