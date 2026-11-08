import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
const RestoreClientController = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RestoreClientController.url(args, options),
    method: 'post',
})

RestoreClientController.definition = {
    methods: ["post"],
    url: '/clients/{client}/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
RestoreClientController.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return RestoreClientController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
RestoreClientController.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RestoreClientController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
const RestoreClientControllerForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RestoreClientController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\RestoreClientController::__invoke
* @see app/Http/Controllers/RestoreClientController.php:16
* @route '/clients/{client}/restore'
*/
RestoreClientControllerForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RestoreClientController.url(args, options),
    method: 'post',
})

RestoreClientController.form = RestoreClientControllerForm

export default RestoreClientController