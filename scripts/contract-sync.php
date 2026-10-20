<?php

/**
 * Publica el contrato OpenAPI versionado (criterio 10 de la Fase 1, ADR-0008).
 *
 * Se ejecuta DENTRO del contenedor (mismo PHP y misma versión de symfony/yaml
 * para todos los entornos), después de `php artisan scribe:generate`:
 *
 *   docker compose exec app php scripts/contract-sync.php     # local
 *   docker compose run --rm app php scripts/contract-sync.php # CI
 *
 * Normaliza el spec antes de escribir la copia que se versiona en `openapi/`:
 * el CI la compara contra una regeneración fresca con `git diff --exit-code`,
 * así que la salida tiene que ser determinista. Los datos volátiles viven en
 * las claves `example`/`examples` — los ejemplos los genera Faker (sembrado,
 * pero con ids medidos en vivo en la BD, timestamps de `now()` y valores por
 * hueco como latencias), así que se eliminan: el contrato conserva rutas,
 * esquemas, tipos, required y descripciones, que es lo que la deriva tiene
 * que detectar.
 */

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$src = 'storage/app/private/scribe/openapi.yaml';
$dest = 'openapi/openapi.yaml';

if (! is_file($src)) {
    fwrite(STDERR, "No existe $src: ejecuta primero 'php artisan scribe:generate'.\n");
    exit(1);
}

$spec = Yaml::parseFile($src);

$stripExamples = static function (mixed $node) use (&$stripExamples): mixed {
    if (! is_array($node)) {
        return $node;
    }

    $out = [];

    foreach ($node as $key => $value) {
        if (is_string($key) && in_array($key, ['example', 'examples'], true)) {
            continue;
        }

        $out[$key] = $stripExamples($value);
    }

    return $out;
};

$spec = $stripExamples($spec);

if (! is_dir(dirname($dest))) {
    mkdir(dirname($dest), 0777, true);
}

// Profundidad de desbordamiento (openapi anida varios niveles), indentado fijo
// de 2 y arrays vacíos como listas (`[]`): `openapi-typescript` espera
// secuencias (`parameters: []`, etc.) — sin el flag quedarían como `{}` y la
// derivación del frontend rompería. La representación resultante es estable
// entre corridas.
file_put_contents($dest, Yaml::dump($spec, 512, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

fwrite(STDOUT, "Contrato versionado actualizado: $dest\n");
