import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
const InvitationResendController = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: InvitationResendController.url(args, options),
    method: 'post',
})

InvitationResendController.definition = {
    methods: ["post"],
    url: '/settings/members/invitations/{invitation}/resend',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
InvitationResendController.url = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { invitation: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { invitation: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            invitation: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        invitation: typeof args.invitation === 'object'
        ? args.invitation.id
        : args.invitation,
    }

    return InvitationResendController.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
InvitationResendController.post = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: InvitationResendController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
const InvitationResendControllerForm = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: InvitationResendController.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
InvitationResendControllerForm.post = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: InvitationResendController.url(args, options),
    method: 'post',
})

InvitationResendController.form = InvitationResendControllerForm

export default InvitationResendController