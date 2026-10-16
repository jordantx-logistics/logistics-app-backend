<?php

namespace Src\System\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Src\System\UseCases\RenderMetricsUseCase;

/**
 * Scrape de Prometheus.
 *
 * No se documenta en Scribe: no forma parte del contrato de la API.
 */
class MetricsController extends Controller
{
    public function __invoke(RenderMetricsUseCase $useCase): Response
    {
        $metrics = $useCase->execute();

        return response($metrics['body'], 200, ['Content-Type' => $metrics['content_type']]);
    }
}
