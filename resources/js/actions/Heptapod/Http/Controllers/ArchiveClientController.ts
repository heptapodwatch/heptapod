import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
const ArchiveClientController = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ArchiveClientController.url(args, options),
    method: 'post',
})

ArchiveClientController.definition = {
    methods: ["post"],
    url: '/clients/{client}/archive',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
ArchiveClientController.url = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ArchiveClientController.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
ArchiveClientController.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ArchiveClientController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
const ArchiveClientControllerForm = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ArchiveClientController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\ArchiveClientController::__invoke
* @see app/Http/Controllers/ArchiveClientController.php:18
* @route '/clients/{client}/archive'
*/
ArchiveClientControllerForm.post = (args: { client: number | { id: number } } | [client: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ArchiveClientController.url(args, options),
    method: 'post',
})

ArchiveClientController.form = ArchiveClientControllerForm

export default ArchiveClientController