import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
import runs from './runs'
/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/import/ohdear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Settings\ImportOhDearController::edit
* @see app/Http/Controllers/Settings/ImportOhDearController.php:26
* @route '/settings/import/ohdear'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
export const plan = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: plan.url(options),
    method: 'post',
})

plan.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/plan',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
plan.url = (options?: RouteQueryOptions) => {
    return plan.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
plan.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: plan.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
const planForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: plan.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
planForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: plan.url(options),
    method: 'post',
})

plan.form = planForm

const ohdear = {
    edit: Object.assign(edit, edit),
    plan: Object.assign(plan, plan),
    runs: Object.assign(runs, runs),
}

export default ohdear