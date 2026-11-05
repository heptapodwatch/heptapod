import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
const ClientDetailController = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientDetailController.url(args, options),
    method: 'get',
})

ClientDetailController.definition = {
    methods: ["get","head"],
    url: '/clients/{client}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
ClientDetailController.url = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: args.client,
    }

    return ClientDetailController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
ClientDetailController.get = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
ClientDetailController.head = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ClientDetailController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
const ClientDetailControllerForm = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
ClientDetailControllerForm.get = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientDetailController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientDetailController::__invoke
* @see app/Http/Controllers/ClientDetailController.php:21
* @route '/clients/{client}'
*/
ClientDetailControllerForm.head = (args: { client: string | number } | [client: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientDetailController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ClientDetailController.form = ClientDetailControllerForm

export default ClientDetailController