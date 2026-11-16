import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
const EmailPreviewController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: EmailPreviewController.url(options),
    method: 'get',
})

EmailPreviewController.definition = {
    methods: ["get","head"],
    url: '/settings/email/preview',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
EmailPreviewController.url = (options?: RouteQueryOptions) => {
    return EmailPreviewController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
EmailPreviewController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: EmailPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
EmailPreviewController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: EmailPreviewController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
const EmailPreviewControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
EmailPreviewControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\EmailPreviewController::__invoke
* @see app/Http/Controllers/Settings/EmailPreviewController.php:25
* @route '/settings/email/preview'
*/
EmailPreviewControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: EmailPreviewController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

EmailPreviewController.form = EmailPreviewControllerForm

export default EmailPreviewController