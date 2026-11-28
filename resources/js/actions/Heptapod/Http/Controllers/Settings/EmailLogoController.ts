import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
const EmailLogoController = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: EmailLogoController.url(args, options),
    method: 'get',
})

EmailLogoController.definition = {
    methods: ["get","head"],
    url: '/settings/email/logo/{variant}/{file}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
EmailLogoController.url = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            variant: args[0],
            file: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        variant: args.variant,
        file: args.file,
    }

    return EmailLogoController.definition.url
            .replace('{variant}', parsedArgs.variant.toString())
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
EmailLogoController.get = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: EmailLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
EmailLogoController.head = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: EmailLogoController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
const EmailLogoControllerForm = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
EmailLogoControllerForm.get = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailLogoController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailLogoController::__invoke
* @see app/Http/Controllers/Settings/EmailLogoController.php:19
* @route '/settings/email/logo/{variant}/{file}'
*/
EmailLogoControllerForm.head = (args: { variant: string | number, file: string | number } | [variant: string | number, file: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailLogoController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

EmailLogoController.form = EmailLogoControllerForm

export default EmailLogoController