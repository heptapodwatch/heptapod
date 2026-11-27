import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:20
* @route '/servers/{server}/sites'
*/
export const store = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/servers/{server}/sites',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:20
* @route '/servers/{server}/sites'
*/
store.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { server: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { server: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            server: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        server: typeof args.server === 'object'
        ? args.server.id
        : args.server,
    }

    return store.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:20
* @route '/servers/{server}/sites'
*/
store.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:20
* @route '/servers/{server}/sites'
*/
const storeForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:20
* @route '/servers/{server}/sites'
*/
storeForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:35
* @route '/servers/{server}/sites/{site}'
*/
export const destroy = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/servers/{server}/sites/{site}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:35
* @route '/servers/{server}/sites/{site}'
*/
destroy.url = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            server: args[0],
            site: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        server: typeof args.server === 'object'
        ? args.server.id
        : args.server,
        site: args.site,
    }

    return destroy.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace('{site}', parsedArgs.site.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:35
* @route '/servers/{server}/sites/{site}'
*/
destroy.delete = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:35
* @route '/servers/{server}/sites/{site}'
*/
const destroyForm = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:35
* @route '/servers/{server}/sites/{site}'
*/
destroyForm.delete = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const ServerSitesController = { store, destroy }

export default ServerSitesController