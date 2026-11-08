import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
const OpenApiDocumentController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OpenApiDocumentController.url(options),
    method: 'get',
})

OpenApiDocumentController.definition = {
    methods: ["get","head"],
    url: '/api/openapi.json',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
OpenApiDocumentController.url = (options?: RouteQueryOptions) => {
    return OpenApiDocumentController.definition.url + queryParams(options)
}

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
OpenApiDocumentController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OpenApiDocumentController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
OpenApiDocumentController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OpenApiDocumentController.url(options),
    method: 'head',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
const OpenApiDocumentControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpenApiDocumentController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
OpenApiDocumentControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpenApiDocumentController.url(options),
    method: 'get',
})

/**
* @see \Heptapod\Http\Controllers\Api\OpenApiDocumentController::__invoke
* @see app/Http/Controllers/Api/OpenApiDocumentController.php:16
* @route '/api/openapi.json'
*/
OpenApiDocumentControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpenApiDocumentController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

OpenApiDocumentController.form = OpenApiDocumentControllerForm

export default OpenApiDocumentController