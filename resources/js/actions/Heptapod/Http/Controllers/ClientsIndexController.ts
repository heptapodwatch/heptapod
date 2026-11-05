import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
const ClientsIndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientsIndexController.url(options),
    method: 'get',
})

ClientsIndexController.definition = {
    methods: ["get","head"],
    url: '/clients',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
ClientsIndexController.url = (options?: RouteQueryOptions) => {
    return ClientsIndexController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
ClientsIndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
ClientsIndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ClientsIndexController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
const ClientsIndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
ClientsIndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientsIndexController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientsIndexController::__invoke
* @see app/Http/Controllers/ClientsIndexController.php:20
* @route '/clients'
*/
ClientsIndexControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientsIndexController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ClientsIndexController.form = ClientsIndexControllerForm

export default ClientsIndexController