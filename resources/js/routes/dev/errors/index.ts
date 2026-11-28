import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
export const show = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/dev/errors/{status}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
show.url = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { status: args }
    }

    if (Array.isArray(args)) {
        args = {
            status: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        status: args.status,
    }

    return show.definition.url
            .replace('{status}', parsedArgs.status.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
show.get = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
show.head = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
const showForm = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
showForm.get = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see routes/web.php:71
* @route '/dev/errors/{status}'
*/
showForm.head = (args: { status: string | number } | [status: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const errors = {
    show: Object.assign(show, show),
}

export default errors