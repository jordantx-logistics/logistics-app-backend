<?php

namespace Src\System\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\System\UseCases\CheckSystemHealthUseCase;

/**
 * @group System - Health
 *
 * Readiness del servicio: comprueba base de datos, Redis y colas.
 */
class HealthController extends Controller
{
    /**
     * Readiness
     *
     * Devuelve 200 si todas las dependencias responden y 503 con el detalle por
     * dependencia si alguna está caída.
     *
     * @response 200 {"success":true,"message":"System healthy.","data":{"status":"ok","checks":{"database":{"status":"up","detail":"conexión \"mysql\" responde","latency_ms":0.6},"redis":{"status":"up","detail":"PONG de \"redis\"","latency_ms":0.3},"queue":{"status":"up","detail":"redis:default (0 pendientes)","latency_ms":0.4}}}}
     * @response 503 {"success":false,"message":"System degraded.","data":{"status":"degraded","checks":{"database":{"status":"up","detail":"conexión \"mysql\" responde","latency_ms":0.6},"redis":{"status":"down","detail":"Connection refused","latency_ms":0.2},"queue":{"status":"up","detail":"redis:default (0 pendientes)","latency_ms":0.4}}}}
     */
    public function show(CheckSystemHealthUseCase $useCase): JsonResponse
    {
        $health = $useCase->execute();

        return $health['status'] === 'ok'
            ? $this->success($health, 'System healthy.')
            : $this->error('System degraded.', $health, 503);
    }
}
