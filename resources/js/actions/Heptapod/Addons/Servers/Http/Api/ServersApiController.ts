import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/servers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::index
* @see addons/servers/src/Http/Api/ServersApiController.php:27
* @route '/api/servers'
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
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
*/
export const show = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/servers/{server}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
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
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
*/
show.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
*/
show.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
*/
const showForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
*/
showForm.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::show
* @see addons/servers/src/Http/Api/ServersApiController.php:39
* @route '/api/servers/{server}'
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
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
export const issues = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: issues.url(args, options),
    method: 'get',
})

issues.definition = {
    methods: ["get","head"],
    url: '/api/servers/{server}/issues',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
issues.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return issues.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
issues.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: issues.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
issues.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: issues.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
const issuesForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: issues.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
issuesForm.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: issues.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::issues
* @see addons/servers/src/Http/Api/ServersApiController.php:47
* @route '/api/servers/{server}/issues'
*/
issuesForm.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: issues.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

issues.form = issuesForm

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::snooze
* @see addons/servers/src/Http/Api/ServersApiController.php:56
* @route '/api/servers/{server}/snooze'
*/
export const snooze = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snooze.url(args, options),
    method: 'post',
})

snooze.definition = {
    methods: ["post"],
    url: '/api/servers/{server}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::snooze
* @see addons/servers/src/Http/Api/ServersApiController.php:56
* @route '/api/servers/{server}/snooze'
*/
snooze.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return snooze.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::snooze
* @see addons/servers/src/Http/Api/ServersApiController.php:56
* @route '/api/servers/{server}/snooze'
*/
snooze.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: snooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::snooze
* @see addons/servers/src/Http/Api/ServersApiController.php:56
* @route '/api/servers/{server}/snooze'
*/
const snoozeForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::snooze
* @see addons/servers/src/Http/Api/ServersApiController.php:56
* @route '/api/servers/{server}/snooze'
*/
snoozeForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: snooze.url(args, options),
    method: 'post',
})

snooze.form = snoozeForm

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::unsnooze
* @see addons/servers/src/Http/Api/ServersApiController.php:64
* @route '/api/servers/{server}/unsnooze'
*/
export const unsnooze = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnooze.url(args, options),
    method: 'post',
})

unsnooze.definition = {
    methods: ["post"],
    url: '/api/servers/{server}/unsnooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::unsnooze
* @see addons/servers/src/Http/Api/ServersApiController.php:64
* @route '/api/servers/{server}/unsnooze'
*/
unsnooze.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return unsnooze.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::unsnooze
* @see addons/servers/src/Http/Api/ServersApiController.php:64
* @route '/api/servers/{server}/unsnooze'
*/
unsnooze.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: unsnooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::unsnooze
* @see addons/servers/src/Http/Api/ServersApiController.php:64
* @route '/api/servers/{server}/unsnooze'
*/
const unsnoozeForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnooze.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\Api\ServersApiController::unsnooze
* @see addons/servers/src/Http/Api/ServersApiController.php:64
* @route '/api/servers/{server}/unsnooze'
*/
unsnoozeForm.post = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: unsnooze.url(args, options),
    method: 'post',
})

unsnooze.form = unsnoozeForm

const ServersApiController = { index, show, issues, snooze, unsnooze }

export default ServersApiController