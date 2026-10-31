<?php

/**
 * Comprueba los umbrales de cobertura de los cierres de Fase 3, 4 y 5.
 *
 * Uso:
 *   php scripts/coverage-check.php build/coverage.xml
 *
 * Reglas:
 *  - `src/` completo: >= 80 % de líneas.
 *  - `src/Logistics/Order/Services/Pricing/`: 100 % de líneas.
 *  - `src/Operations/`: >= 80 % de líneas (Fase 4).
 *  - `src/Finance/`: >= 80 % de líneas (Fase 5).
 *  - `src/Finance/{Cash,Void}/UseCases/`: 100 % de líneas (Fase 5).
 *  - `src/Fleet/`: >= 80 % de líneas (Fase 6).
 *
 * Pcov no mide ramas; el 100 % del motor de precios se complementa con la
 * matriz de escenarios de `tests/Feature/Logistics/OrderFlowTest.php` y
 * `tests/Unit/Logistics/TariffPricingEngineTest.php`. En Operaciones, el
 * umbral se complementa con la matriz de escenarios de
 * `tests/Feature/Operations/`. El «100 % de ramas críticas» de caja y anulación
 * se interpreta igual: 100 % de líneas de sus casos de uso, donde vive cada
 * regla, más `tests/Feature/Finance/` y `scripts/finance-concurrency-check.php`.
 */
$path = $argv[1] ?? 'build/coverage.xml';

if (! is_file($path)) {
    fwrite(STDERR, "No existe el informe de cobertura: {$path}\n");
    exit(1);
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "No se pudo leer el XML de cobertura: {$path}\n");
    exit(1);
}

$files = $xml->xpath('//file');

if ($files === false || $files === []) {
    fwrite(STDERR, "El informe no contiene archivos.\n");
    exit(1);
}

$totals = ['covered' => 0, 'total' => 0];
$pricing = ['covered' => 0, 'total' => 0];
$operations = ['covered' => 0, 'total' => 0];
$finance = ['covered' => 0, 'total' => 0];
$financeCritical = ['covered' => 0, 'total' => 0];
$fleet = ['covered' => 0, 'total' => 0];

foreach ($files as $file) {
    $name = str_replace('\\', '/', (string) $file['name']);
    $relative = str_contains($name, '/src/') ? substr($name, strpos($name, '/src/') + 1) : $name;

    if (! str_starts_with($relative, 'src/')) {
        continue;
    }

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $count = (int) $line['count'];

        $totals['total']++;
        $totals['covered'] += $count > 0 ? 1 : 0;

        if (str_starts_with($relative, 'src/Logistics/Order/Services/Pricing/')) {
            $pricing['total']++;
            $pricing['covered'] += $count > 0 ? 1 : 0;
        }

        if (str_starts_with($relative, 'src/Operations/')) {
            $operations['total']++;
            $operations['covered'] += $count > 0 ? 1 : 0;
        }

        if (str_starts_with($relative, 'src/Finance/')) {
            $finance['total']++;
            $finance['covered'] += $count > 0 ? 1 : 0;
        }

        if (str_starts_with($relative, 'src/Finance/Cash/UseCases/') || str_starts_with($relative, 'src/Finance/Void/UseCases/')) {
            $financeCritical['total']++;
            $financeCritical['covered'] += $count > 0 ? 1 : 0;
        }

        if (str_starts_with($relative, 'src/Fleet/')) {
            $fleet['total']++;
            $fleet['covered'] += $count > 0 ? 1 : 0;
        }
    }
}

$percent = static fn (array $bucket): float => $bucket['total'] === 0
    ? 0.0
    : round($bucket['covered'] / $bucket['total'] * 100, 2);

$srcPercent = $percent($totals);
$pricingPercent = $percent($pricing);
$operationsPercent = $percent($operations);
$financePercent = $percent($finance);
$financeCriticalPercent = $percent($financeCritical);
$fleetPercent = $percent($fleet);

printf("Cobertura src/: %.2f %% (%d/%d)\n", $srcPercent, $totals['covered'], $totals['total']);
printf("Cobertura pricing: %.2f %% (%d/%d)\n", $pricingPercent, $pricing['covered'], $pricing['total']);
printf("Cobertura operations: %.2f %% (%d/%d)\n", $operationsPercent, $operations['covered'], $operations['total']);
printf("Cobertura finance: %.2f %% (%d/%d)\n", $financePercent, $finance['covered'], $finance['total']);
printf("Cobertura caja y anulación: %.2f %% (%d/%d)\n", $financeCriticalPercent, $financeCritical['covered'], $financeCritical['total']);
printf("Cobertura fleet: %.2f %% (%d/%d)\n", $fleetPercent, $fleet['covered'], $fleet['total']);

$failed = false;

if ($srcPercent < 80.0) {
    fwrite(STDERR, "FALLO: src/ debe tener al menos 80 % de líneas.\n");
    $failed = true;
}

if ($pricing['total'] === 0) {
    fwrite(STDERR, "FALLO: no se encontraron líneas del motor de precios en el informe.\n");
    $failed = true;
} elseif ($pricingPercent < 100.0) {
    fwrite(STDERR, "FALLO: el motor de precios debe tener 100 % de líneas.\n");
    $failed = true;
}

if ($operations['total'] === 0) {
    fwrite(STDERR, "FALLO: no se encontraron líneas de Operaciones en el informe.\n");
    $failed = true;
} elseif ($operationsPercent < 80.0) {
    fwrite(STDERR, "FALLO: src/Operations/ debe tener al menos 80 % de líneas.\n");
    $failed = true;
}

if ($finance['total'] === 0) {
    fwrite(STDERR, "FALLO: no se encontraron líneas de Finanzas en el informe.\n");
    $failed = true;
} elseif ($financePercent < 80.0) {
    fwrite(STDERR, "FALLO: src/Finance/ debe tener al menos 80 % de líneas.\n");
    $failed = true;
}

if ($financeCritical['total'] === 0) {
    fwrite(STDERR, "FALLO: no se encontraron líneas de caja y anulación en el informe.\n");
    $failed = true;
} elseif ($financeCriticalPercent < 100.0) {
    fwrite(STDERR, "FALLO: los casos de uso de caja y anulación deben tener 100 % de líneas.\n");
    $failed = true;
}

if ($fleet['total'] === 0) {
    fwrite(STDERR, "FALLO: no se encontraron líneas de Flota en el informe.\n");
    $failed = true;
} elseif ($fleetPercent < 80.0) {
    fwrite(STDERR, "FALLO: src/Fleet/ debe tener al menos 80 % de líneas.\n");
    $failed = true;
}

exit($failed ? 1 : 0);
