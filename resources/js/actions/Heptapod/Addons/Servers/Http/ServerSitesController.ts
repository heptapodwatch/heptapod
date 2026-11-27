import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
export const index = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/servers/{server}/sites',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
index.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
index.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
index.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
const indexForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
indexForm.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::index
* @see addons/servers/src/Http/ServerSitesController.php:34
* @route '/servers/{server}/sites'
*/
indexForm.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:73
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
* @see addons/servers/src/Http/ServerSitesController.php:73
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
* @see addons/servers/src/Http/ServerSitesController.php:73
* @route '/servers/{server}/sites'
*/
store.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:73
* @route '/servers/{server}/sites'
*/
const storeForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::store
* @see addons/servers/src/Http/ServerSitesController.php:73
* @route '/servers/{server}/sites'
*/
storeForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:88
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
* @see addons/servers/src/Http/ServerSitesController.php:88
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
* @see addons/servers/src/Http/ServerSitesController.php:88
* @route '/servers/{server}/sites/{site}'
*/
destroy.delete = (args: { server: number | { id: number }, site: string | number } | [server: number | { id: number }, site: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerSitesController::destroy
* @see addons/servers/src/Http/ServerSitesController.php:88
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
* @see addons/servers/src/Http/ServerSitesController.php:88
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

const ServerSitesController = { index, store, destroy }

export default ServerSitesController