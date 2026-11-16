import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
const MailBrandingPreviewController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MailBrandingPreviewController.url(options),
    method: 'get',
})

MailBrandingPreviewController.definition = {
    methods: ["get","head"],
    url: '/dev/mail-branding/preview',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
MailBrandingPreviewController.url = (options?: RouteQueryOptions) => {
    return MailBrandingPreviewController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
MailBrandingPreviewController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MailBrandingPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
MailBrandingPreviewController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: MailBrandingPreviewController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
const MailBrandingPreviewControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MailBrandingPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
MailBrandingPreviewControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MailBrandingPreviewController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
MailBrandingPreviewControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: MailBrandingPreviewController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

MailBrandingPreviewController.form = MailBrandingPreviewControllerForm

export default MailBrandingPreviewController