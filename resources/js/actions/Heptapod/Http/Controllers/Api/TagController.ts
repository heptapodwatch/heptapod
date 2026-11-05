import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/tags',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::index
* @see app/Http/Controllers/Api/TagController.php:26
* @route '/api/tags'
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
* @see \Heptapod\Http\Controllers\Api\TagController::store
* @see app/Http/Controllers/Api/TagController.php:45
* @route '/api/tags'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/tags',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Api\TagController::store
* @see app/Http/Controllers/Api/TagController.php:45
* @route '/api/tags'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\TagController::store
* @see app/Http/Controllers/Api/TagController.php:45
* @route '/api/tags'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::store
* @see app/Http/Controllers/Api/TagController.php:45
* @route '/api/tags'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Api\TagController::store
* @see app/Http/Controllers/Api/TagController.php:45
* @route '/api/tags'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

const TagController = { index, store }

export default TagController