import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
export const show = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/sites/{site}/icon/{hash}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
show.url = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            hash: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: typeof args.site === 'object'
        ? args.site.id
        : args.site,
        hash: args.hash,
    }

    return show.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{hash}', parsedArgs.hash.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
show.get = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
show.head = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
const showForm = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
showForm.get = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\SiteIconController::show
* @see app/Http/Controllers/SiteIconController.php:14
* @route '/sites/{site}/icon/{hash}'
*/
showForm.head = (args: { site: number | { id: number }, hash: string | number } | [site: number | { id: number }, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const SiteIconController = { show }

export default SiteIconController