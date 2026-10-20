<?php

namespace Src\Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

trait ApiResponse
{
    /**
     * Return a standardized success JSON response.
     */
    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            ...($data !== null ? ['data' => $data] : []),
        ], $statusCode);
    }

    /**
     * Respuesta de éxito de un listado paginado.
     *
     * No se arma a mano: el recurso serializa el paginador y Laravel ya emite
     * ahí `data` + `meta` + `links`, que es la forma estándar del spec
     * (`spec/08-calidad/02-contrato-openapi-frontend.md`). Aquí solo se añade el
     * sobre del API por encima, con `additional()`.
     *
     * Es la misma ruta de serialización que usa Scribe para documentar
     * `@apiResourceCollection ... paginate=N`, así que el contrato y la
     * respuesta no pueden separarse.
     */
    protected function successPaginated(
        AnonymousResourceCollection $resource,
        string $message = 'Success',
    ): JsonResponse {
        return $resource->additional([
            'success' => true,
            'message' => $message,
        ])->response();
    }

    /**
     * Return a standardized error JSON response.
     */
    protected function error(string $message, mixed $data = null, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            ...($data !== null ? ['data' => $data] : []),
        ], $statusCode);
    }
}
