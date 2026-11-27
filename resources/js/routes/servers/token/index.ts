import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotate
* @see addons/servers/src/Http/ServersController.php:118
* @route '/servers/{server}/token'
*/
export const rotate = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

rotate.definition = {
    methods: ["post"],
    url: '/servers/{server}/token',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotate
* @see addons/servers/src/Http/ServersController.php:118
* @route '/servers/{server}/token'
*/
rotate.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return rotate.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotate
* @see addons/servers/src/Http/ServersController.php:118
* @route '/servers/{server}/token'
*/
rotate.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotate
* @see addons/servers/src/Http/ServersController.php:118
* @route '/servers/{server}/token'
*/
const rotateForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::rotate
* @see addons/servers/src/Http/ServersController.php:118
* @route '/servers/{server}/token'
*/
rotateForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

rotate.form = rotateForm

const token = {
    rotate: Object.assign(rotate, rotate),
}

export default token