import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
const ClientMessagePreviewController = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ClientMessagePreviewController.url(args, options),
    method: 'post',
})

ClientMessagePreviewController.definition = {
    methods: ["post"],
    url: '/clients/{client}/client-messages/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
ClientMessagePreviewController.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ClientMessagePreviewController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
ClientMessagePreviewController.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ClientMessagePreviewController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
const ClientMessagePreviewControllerForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ClientMessagePreviewController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ClientMessagePreviewController::__invoke
* @see app/Http/Controllers/ClientMessagePreviewController.php:17
* @route '/clients/{client}/client-messages/preview'
*/
ClientMessagePreviewControllerForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ClientMessagePreviewController.url(args, options),
    method: 'post',
})

ClientMessagePreviewController.form = ClientMessagePreviewControllerForm

export default ClientMessagePreviewController