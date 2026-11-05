import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/members',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::index
* @see app/Http/Controllers/Settings/MembersController.php:25
* @route '/settings/members'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
export const data = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

data.definition = {
    methods: ["get","head"],
    url: '/settings/members/data',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
data.url = (options?: RouteQueryOptions) => {
    return data.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
data.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
data.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: data.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
const dataForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
dataForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::data
* @see app/Http/Controllers/Settings/MembersController.php:32
* @route '/settings/members/data'
*/
dataForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: data.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

data.form = dataForm

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::invite
* @see app/Http/Controllers/Settings/MembersController.php:37
* @route '/settings/members/invitations'
*/
export const invite = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invite.url(options),
    method: 'post',
})

invite.definition = {
    methods: ["post"],
    url: '/settings/members/invitations',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::invite
* @see app/Http/Controllers/Settings/MembersController.php:37
* @route '/settings/members/invitations'
*/
invite.url = (options?: RouteQueryOptions) => {
    return invite.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::invite
* @see app/Http/Controllers/Settings/MembersController.php:37
* @route '/settings/members/invitations'
*/
invite.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invite.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::invite
* @see app/Http/Controllers/Settings/MembersController.php:37
* @route '/settings/members/invitations'
*/
const inviteForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: invite.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::invite
* @see app/Http/Controllers/Settings/MembersController.php:37
* @route '/settings/members/invitations'
*/
inviteForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: invite.url(options),
    method: 'post',
})

invite.form = inviteForm

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
export const resend = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(args, options),
    method: 'post',
})

resend.definition = {
    methods: ["post"],
    url: '/settings/members/invitations/{invitation}/resend',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
resend.url = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return resend.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
resend.post = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
const resendForm = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: resend.url(args, options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationResendController::__invoke
* @see app/Http/Controllers/Settings/InvitationResendController.php:16
* @route '/settings/members/invitations/{invitation}/resend'
*/
resendForm.post = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: resend.url(args, options),
    method: 'post',
})

resend.form = resendForm

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
export const revoke = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: revoke.url(args, options),
    method: 'delete',
})

revoke.definition = {
    methods: ["delete"],
    url: '/settings/members/invitations/{invitation}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
revoke.url = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return revoke.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
revoke.delete = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: revoke.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Settings\InvitationRevokeController::__invoke
* @see app/Http/Controllers/Settings/InvitationRevokeController.php:16
* @route '/settings/members/invitations/{invitation}'
*/
const revokeForm = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: revoke.url(args, {
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
revokeForm.delete = (args: { invitation: number | { id: number } } | [invitation: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: revoke.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

revoke.form = revokeForm

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::update
* @see app/Http/Controllers/Settings/MembersController.php:48
* @route '/settings/members/{member}'
*/
export const update = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/members/{member}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::update
* @see app/Http/Controllers/Settings/MembersController.php:48
* @route '/settings/members/{member}'
*/
update.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { member: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { member: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            member: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        member: typeof args.member === 'object'
        ? args.member.id
        : args.member,
    }

    return update.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::update
* @see app/Http/Controllers/Settings/MembersController.php:48
* @route '/settings/members/{member}'
*/
update.patch = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::update
* @see app/Http/Controllers/Settings/MembersController.php:48
* @route '/settings/members/{member}'
*/
const updateForm = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::update
* @see app/Http/Controllers/Settings/MembersController.php:48
* @route '/settings/members/{member}'
*/
updateForm.patch = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::remove
* @see app/Http/Controllers/Settings/MembersController.php:58
* @route '/settings/members/{member}'
*/
export const remove = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

remove.definition = {
    methods: ["delete"],
    url: '/settings/members/{member}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::remove
* @see app/Http/Controllers/Settings/MembersController.php:58
* @route '/settings/members/{member}'
*/
remove.url = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { member: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { member: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            member: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        member: typeof args.member === 'object'
        ? args.member.id
        : args.member,
    }

    return remove.definition.url
            .replace('{member}', parsedArgs.member.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::remove
* @see app/Http/Controllers/Settings/MembersController.php:58
* @route '/settings/members/{member}'
*/
remove.delete = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::remove
* @see app/Http/Controllers/Settings/MembersController.php:58
* @route '/settings/members/{member}'
*/
const removeForm = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: remove.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\MembersController::remove
* @see app/Http/Controllers/Settings/MembersController.php:58
* @route '/settings/members/{member}'
*/
removeForm.delete = (args: { member: number | { id: number } } | [member: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: remove.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

remove.form = removeForm

const members = {
    index: Object.assign(index, index),
    data: Object.assign(data, data),
    invite: Object.assign(invite, invite),
    resend: Object.assign(resend, resend),
    revoke: Object.assign(revoke, revoke),
    update: Object.assign(update, update),
    remove: Object.assign(remove, remove),
}

export default members