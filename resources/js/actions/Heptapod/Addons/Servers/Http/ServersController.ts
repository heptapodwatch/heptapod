import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/servers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:22
* @route '/servers'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:29
* @route '/servers'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/servers',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:29
* @route '/servers'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:29
* @route '/servers'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:29
* @route '/servers'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:29
* @route '/servers'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
export const show = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/servers/{server}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
show.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
show.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
show.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
const showForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
showForm.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:40
* @route '/servers/{server}'
*/
showForm.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotateToken
* @see addons/servers/src/Http/ServersController.php:56
* @route '/servers/{server}/token'
*/
export const rotateToken = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateToken.url(args, options),
    method: 'post',
})

rotateToken.definition = {
    methods: ["post"],
    url: '/servers/{server}/token',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotateToken
* @see addons/servers/src/Http/ServersController.php:56
* @route '/servers/{server}/token'
*/
rotateToken.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return rotateToken.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotateToken
* @see addons/servers/src/Http/ServersController.php:56
* @route '/servers/{server}/token'
*/
rotateToken.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateToken.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotateToken
* @see addons/servers/src/Http/ServersController.php:56
* @route '/servers/{server}/token'
*/
const rotateTokenForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateToken.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotateToken
* @see addons/servers/src/Http/ServersController.php:56
* @route '/servers/{server}/token'
*/
rotateTokenForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateToken.url(args, options),
    method: 'post',
})

rotateToken.form = rotateTokenForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:65
* @route '/servers/{server}'
*/
export const destroy = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/servers/{server}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:65
* @route '/servers/{server}'
*/
destroy.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:65
* @route '/servers/{server}'
*/
destroy.delete = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:65
* @route '/servers/{server}'
*/
const destroyForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:65
* @route '/servers/{server}'
*/
destroyForm.delete = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const ServersController = { index, store, show, rotateToken, destroy }

export default ServersController