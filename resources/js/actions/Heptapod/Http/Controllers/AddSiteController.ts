import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/sites/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
const createForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
createForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::create
* @see app/Http/Controllers/AddSiteController.php:25
* @route '/sites/create'
*/
createForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: create.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

create.form = createForm

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/sites',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AddSiteController::store
* @see app/Http/Controllers/AddSiteController.php:35
* @route '/sites'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

const AddSiteController = { create, store }

export default AddSiteController