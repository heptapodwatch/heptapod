import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::store
* @see app/Http/Controllers/SiteCheckSnoozeController.php:20
* @route '/sites/{site}/checks/{check}/snooze'
*/
export const store = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/sites/{site}/checks/{check}/snooze',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::store
* @see app/Http/Controllers/SiteCheckSnoozeController.php:20
* @route '/sites/{site}/checks/{check}/snooze'
*/
store.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::store
* @see app/Http/Controllers/SiteCheckSnoozeController.php:20
* @route '/sites/{site}/checks/{check}/snooze'
*/
store.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::store
* @see app/Http/Controllers/SiteCheckSnoozeController.php:20
* @route '/sites/{site}/checks/{check}/snooze'
*/
const storeForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::store
* @see app/Http/Controllers/SiteCheckSnoozeController.php:20
* @route '/sites/{site}/checks/{check}/snooze'
*/
storeForm.post = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::destroy
* @see app/Http/Controllers/SiteCheckSnoozeController.php:30
* @route '/sites/{site}/checks/{check}/snooze'
*/
export const destroy = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/sites/{site}/checks/{check}/snooze',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::destroy
* @see app/Http/Controllers/SiteCheckSnoozeController.php:30
* @route '/sites/{site}/checks/{check}/snooze'
*/
destroy.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::destroy
* @see app/Http/Controllers/SiteCheckSnoozeController.php:30
* @route '/sites/{site}/checks/{check}/snooze'
*/
destroy.delete = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::destroy
* @see app/Http/Controllers/SiteCheckSnoozeController.php:30
* @route '/sites/{site}/checks/{check}/snooze'
*/
const destroyForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\SiteCheckSnoozeController::destroy
* @see app/Http/Controllers/SiteCheckSnoozeController.php:30
* @route '/sites/{site}/checks/{check}/snooze'
*/
destroyForm.delete = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const SiteCheckSnoozeController = { store, destroy }

export default SiteCheckSnoozeController