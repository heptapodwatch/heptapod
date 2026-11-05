import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
const UpdateCheckSettingsController = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateCheckSettingsController.url(args, options),
    method: 'patch',
})

UpdateCheckSettingsController.definition = {
    methods: ["patch"],
    url: '/sites/{site}/checks/{check}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
UpdateCheckSettingsController.url = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions) => {
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

    return UpdateCheckSettingsController.definition.url
            .replace('{site}', parsedArgs.site.toString())
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
UpdateCheckSettingsController.patch = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateCheckSettingsController.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
const UpdateCheckSettingsControllerForm = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateCheckSettingsController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\UpdateCheckSettingsController::__invoke
* @see app/Http/Controllers/UpdateCheckSettingsController.php:21
* @route '/sites/{site}/checks/{check}'
*/
UpdateCheckSettingsControllerForm.patch = (args: { site: string | number, check: string | number } | [site: string | number, check: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateCheckSettingsController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

UpdateCheckSettingsController.form = UpdateCheckSettingsControllerForm

export default UpdateCheckSettingsController