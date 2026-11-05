import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
const AcceptDnsSnapshotController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AcceptDnsSnapshotController.url(args, options),
    method: 'post',
})

AcceptDnsSnapshotController.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/accept-dns',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
AcceptDnsSnapshotController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            site: args[0],
            check: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        site: args.site,
        check: args.check,
    }

    return AcceptDnsSnapshotController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
AcceptDnsSnapshotController.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AcceptDnsSnapshotController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
const AcceptDnsSnapshotControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AcceptDnsSnapshotController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\AcceptDnsSnapshotController::__invoke
* @see app/Http/Controllers/AcceptDnsSnapshotController.php:22
* @route '/sites/{site}/checks/{check}/accept-dns'
*/
AcceptDnsSnapshotControllerForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AcceptDnsSnapshotController.url(args, options),
    method: 'post',
})

AcceptDnsSnapshotController.form = AcceptDnsSnapshotControllerForm

export default AcceptDnsSnapshotController