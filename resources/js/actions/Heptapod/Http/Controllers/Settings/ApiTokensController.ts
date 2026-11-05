import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/api-tokens',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::edit
* @see app/Http/Controllers/Settings/ApiTokensController.php:28
* @route '/settings/api-tokens'
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
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/api-tokens/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::index
* @see app/Http/Controllers/Settings/ApiTokensController.php:35
* @route '/settings/api-tokens/data'
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
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::store
* @see app/Http/Controllers/Settings/ApiTokensController.php:67
* @route '/settings/api-tokens'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/api-tokens',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::store
* @see app/Http/Controllers/Settings/ApiTokensController.php:67
* @route '/settings/api-tokens'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::store
* @see app/Http/Controllers/Settings/ApiTokensController.php:67
* @route '/settings/api-tokens'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::store
* @see app/Http/Controllers/Settings/ApiTokensController.php:67
* @route '/settings/api-tokens'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::store
* @see app/Http/Controllers/Settings/ApiTokensController.php:67
* @route '/settings/api-tokens'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::destroy
* @see app/Http/Controllers/Settings/ApiTokensController.php:83
* @route '/settings/api-tokens/{token}'
*/
export const destroy = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/api-tokens/{token}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::destroy
* @see app/Http/Controllers/Settings/ApiTokensController.php:83
* @route '/settings/api-tokens/{token}'
*/
destroy.url = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { token: args }
    }

    if (Array.isArray(args)) {
        args = {
            token: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        token: args.token,
    }

    return destroy.definition.url
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::destroy
* @see app/Http/Controllers/Settings/ApiTokensController.php:83
* @route '/settings/api-tokens/{token}'
*/
destroy.delete = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::destroy
* @see app/Http/Controllers/Settings/ApiTokensController.php:83
* @route '/settings/api-tokens/{token}'
*/
const destroyForm = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ApiTokensController::destroy
* @see app/Http/Controllers/Settings/ApiTokensController.php:83
* @route '/settings/api-tokens/{token}'
*/
destroyForm.delete = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const ApiTokensController = { edit, index, store, destroy }

export default ApiTokensController