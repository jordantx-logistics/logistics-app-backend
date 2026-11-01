<?php

namespace Src\Reporting\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Dashboard
 *
 * Indicadores según lo que cada rol puede ver.
 */
class DashboardController extends Controller
{
    /**
     * @param  iterable<KpiSource>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
    ) {}

    /**
     * Dashboard indicators
     *
     * Only the indicators your permissions allow, limited to your agency scope.
     * They may lag up to a minute.
     *
     * @response 200 scenario="Indicators" {"success": true, "message": "Indicators retrieved successfully.", "data": [{"key": "orders_today", "label": "Orders today", "value": "12", "format": "number"}]}
     */
    public function kpis(Request $request, AgencyScope $scope): JsonResponse
    {
        $kpis = [];

        foreach ($this->sources as $source) {
            if ($request->user()->can($source->permission())) {
                array_push($kpis, ...$this->cached($source, $scope));
            }
        }

        return $this->success($kpis, 'Indicators retrieved successfully.');
    }

    /**
     * @return list<array{key: string, label: string, value: string, format: string}>
     */
    private function cached(KpiSource $source, AgencyScope $scope): array
    {
        $ids = $scope->ids;
        sort($ids);

        $key = sprintf('dashboard:%s:%s', $source::class, $scope->unrestricted ? 'national' : md5(implode(',', $ids)));

        return Cache::remember($key, (int) config('reporting.kpi_cache_ttl'), fn (): array => array_map(
            fn (Kpi $kpi): array => ['key' => $kpi->key, 'label' => $kpi->label, 'value' => $kpi->value, 'format' => $kpi->format],
            $source->kpis($scope),
        ));
    }
}
