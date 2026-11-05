import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
const InvitationRevokeController = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: InvitationRevokeController.url(args, options),
    method: 'delete',
})

InvitationRevokeController.definition = {
    methods: ["delete"],
    url: '/settings/members/invitations/{invitation}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
InvitationRevokeController.url = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return InvitationRevokeController.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
InvitationRevokeController.delete = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: InvitationRevokeController.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
const InvitationRevokeControllerForm = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: InvitationRevokeController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
InvitationRevokeControllerForm.delete = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: InvitationRevokeController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

InvitationRevokeController.form = InvitationRevokeControllerForm

export default InvitationRevokeController