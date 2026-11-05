import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
const SearchController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SearchController.url(options),
    method: 'get',
})

SearchController.definition = {
    methods: ["get","head"],
    url: '/search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
SearchController.url = (options?: RouteQueryOptions) => {
    return SearchController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
SearchController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SearchController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
SearchController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SearchController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
const SearchControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SearchController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
SearchControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SearchController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SearchController::__invoke
* @see app/Http/Controllers/SearchController.php:15
* @route '/search'
*/
SearchControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: SearchController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

SearchController.form = SearchControllerForm

export default SearchController