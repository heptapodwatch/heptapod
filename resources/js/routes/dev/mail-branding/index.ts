import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
export const preview = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: preview.url(options),
    method: 'get',
})

preview.definition = {
    methods: ["get","head"],
    url: '/dev/mail-branding/preview',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
preview.url = (options?: RouteQueryOptions) => {
    return preview.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
preview.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: preview.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
preview.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: preview.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
const previewForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: preview.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
previewForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: preview.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Dev\MailBrandingPreviewController::__invoke
* @see app/Http/Controllers/Dev/MailBrandingPreviewController.php:28
* @route '/dev/mail-branding/preview'
*/
previewForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: preview.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

preview.form = previewForm

const mailBranding = {
    preview: Object.assign(preview, preview),
}

export default mailBranding