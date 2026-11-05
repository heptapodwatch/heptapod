import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
const PlanOhDearImportController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PlanOhDearImportController.url(options),
    method: 'post',
})

PlanOhDearImportController.definition = {
    methods: ["post"],
    url: '/settings/import/ohdear/plan',
} satisfies RouteDefinition<["post"]>

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
PlanOhDearImportController.url = (options?: RouteQueryOptions) => {
    return PlanOhDearImportController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
PlanOhDearImportController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PlanOhDearImportController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
const PlanOhDearImportControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: PlanOhDearImportController.url(options),
    method: 'post',
})

/**
* @see \Heptapod\Http\Controllers\Settings\PlanOhDearImportController::__invoke
* @see app/Http/Controllers/Settings/PlanOhDearImportController.php:19
* @route '/settings/import/ohdear/plan'
*/
PlanOhDearImportControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: PlanOhDearImportController.url(options),
    method: 'post',
})

PlanOhDearImportController.form = PlanOhDearImportControllerForm

export default PlanOhDearImportController