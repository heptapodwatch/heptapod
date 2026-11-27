import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import token from './token'
import sites from './sites'
/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
export const report = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: report.url(options),
    method: 'post',
})

report.definition = {
    methods: ["post"],
    url: '/agent/v1/report',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
report.url = (options?: RouteQueryOptions) => {
    return report.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
report.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: report.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
const reportForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: report.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServerReportController::__invoke
* @see addons/servers/src/Http/ServerReportController.php:35
* @route '/agent/v1/report'
*/
reportForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: report.url(options),
    method: 'post',
})

report.form = reportForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
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
* @see addons/servers/src/Http/ServersController.php:36
* @route '/servers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
* @route '/servers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
* @route '/servers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
* @route '/servers'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
* @route '/servers'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::index
* @see addons/servers/src/Http/ServersController.php:36
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
* @see addons/servers/src/Http/ServersController.php:61
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
* @see addons/servers/src/Http/ServersController.php:61
* @route '/servers'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:61
* @route '/servers'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:61
* @route '/servers'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::store
* @see addons/servers/src/Http/ServersController.php:61
* @route '/servers'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:72
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
* @see addons/servers/src/Http/ServersController.php:72
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
* @see addons/servers/src/Http/ServersController.php:72
* @route '/servers/{server}'
*/
show.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:72
* @route '/servers/{server}'
*/
show.head = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:72
* @route '/servers/{server}'
*/
const showForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:72
* @route '/servers/{server}'
*/
showForm.get = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::show
* @see addons/servers/src/Http/ServersController.php:72
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
* @see \Heptapod\Addons\Servers\Http\ServersController::update
* @see addons/servers/src/Http/ServersController.php:122
* @route '/servers/{server}'
*/
export const update = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/servers/{server}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::update
* @see addons/servers/src/Http/ServersController.php:122
* @route '/servers/{server}'
*/
update.url = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{server}', parsedArgs.server.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::update
* @see addons/servers/src/Http/ServersController.php:122
* @route '/servers/{server}'
*/
update.patch = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::update
* @see addons/servers/src/Http/ServersController.php:122
* @route '/servers/{server}'
*/
const updateForm = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::update
* @see addons/servers/src/Http/ServersController.php:122
* @route '/servers/{server}'
*/
updateForm.patch = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:152
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
* @see addons/servers/src/Http/ServersController.php:152
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
* @see addons/servers/src/Http/ServersController.php:152
* @route '/servers/{server}'
*/
destroy.delete = (args: { server: number | { id: number } } | [server: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Addons\Servers\Http\ServersController::destroy
* @see addons/servers/src/Http/ServersController.php:152
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
* @see addons/servers/src/Http/ServersController.php:152
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

const servers = {
    report: Object.assign(report, report),
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    show: Object.assign(show, show),
    update: Object.assign(update, update),
    token: Object.assign(token, token),
    sites: Object.assign(sites, sites),
    destroy: Object.assign(destroy, destroy),
}

export default servers