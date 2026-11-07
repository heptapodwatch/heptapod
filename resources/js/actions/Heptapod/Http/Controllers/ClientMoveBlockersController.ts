import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
const ClientMoveBlockersController = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientMoveBlockersController.url(args, options),
    method: 'get',
})

ClientMoveBlockersController.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/move-blockers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
ClientMoveBlockersController.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { client: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
    }

    return ClientMoveBlockersController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
ClientMoveBlockersController.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientMoveBlockersController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
ClientMoveBlockersController.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ClientMoveBlockersController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
const ClientMoveBlockersControllerForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMoveBlockersController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
ClientMoveBlockersControllerForm.get = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMoveBlockersController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMoveBlockersController::__invoke
* @see app/Http/Controllers/ClientMoveBlockersController.php:16
* @route '/clients/{client}/move-blockers'
*/
ClientMoveBlockersControllerForm.head = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMoveBlockersController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ClientMoveBlockersController.form = ClientMoveBlockersControllerForm

export default ClientMoveBlockersController