import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
const PickerController = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PickerController.url(args, options),
    method: 'get',
})

PickerController.definition = {
    methods: ["get","head"],
    url: '/pickers/{kind}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
PickerController.url = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { kind: args }
    }

    if (Array.isArray(args)) {
        args = {
            kind: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        kind: args.kind,
    }

    return PickerController.definition.url
            .replace('{kind}', parsedArgs.kind.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
PickerController.get = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PickerController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
PickerController.head = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PickerController.url(args, options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
const PickerControllerForm = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: PickerController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
PickerControllerForm.get = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: PickerController.url(args, options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\PickerController::__invoke
* @see app/Http/Controllers/PickerController.php:16
* @route '/pickers/{kind}'
*/
PickerControllerForm.head = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: PickerController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

PickerController.form = PickerControllerForm

export default PickerController