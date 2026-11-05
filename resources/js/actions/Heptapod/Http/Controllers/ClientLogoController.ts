import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
const ClientLogoController = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientLogoController.url(args, options),
    method: 'get',
})

ClientLogoController.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/logo/{hash}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
ClientLogoController.url = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            hash: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
        hash: args.hash,
    }

    return ClientLogoController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{hash}', parsedArgs.hash.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
ClientLogoController.get = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
ClientLogoController.head = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ClientLogoController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
const ClientLogoControllerForm = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
ClientLogoControllerForm.get = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientLogoController::__invoke
* @see app/Http/Controllers/ClientLogoController.php:15
* @route '/clients/{client}/logo/{hash}'
*/
ClientLogoControllerForm.head = (args: { client: number | { id: number }, hash: string | number } | [client: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientLogoController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ClientLogoController.form = ClientLogoControllerForm

export default ClientLogoController