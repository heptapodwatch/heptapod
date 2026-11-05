import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
const ClientMessageAttachmentController = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientMessageAttachmentController.url(args, options),
    method: 'get',
})

ClientMessageAttachmentController.definition = {
    methods: ["get","head"],
    url: '/clients/{client}/client-messages/{message}/attachment',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
ClientMessageAttachmentController.url = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.id
        : args.client,
        message: args.message,
    }

    return ClientMessageAttachmentController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
ClientMessageAttachmentController.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ClientMessageAttachmentController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
ClientMessageAttachmentController.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ClientMessageAttachmentController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
const ClientMessageAttachmentControllerForm = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMessageAttachmentController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
ClientMessageAttachmentControllerForm.get = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMessageAttachmentController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessageAttachmentController::__invoke
* @see app/Http/Controllers/ClientMessageAttachmentController.php:19
* @route '/clients/{client}/client-messages/{message}/attachment'
*/
ClientMessageAttachmentControllerForm.head = (args: { client: number | { id: number }, message: string | number } | [client: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ClientMessageAttachmentController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ClientMessageAttachmentController.form = ClientMessageAttachmentControllerForm

export default ClientMessageAttachmentController