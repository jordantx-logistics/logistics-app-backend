<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Padrón de ubigeos → zonas y ubicaciones.
 *
 * Fuente: `database/data/ubigeo.csv`, copia del padrón INEI publicado por
 * CONCYTEC (`https://github.com/CONCYTEC/ubigeo-peru`, archivo
 * `equivalencia-ubigeos-oti-concytec.csv`). Se usan las columnas INEI por
 * índice: `cod_dep` (0), `desc_dep` (1), `desc_prov` (3), `cod_ubigeo` (4) y
 * `desc_ubigeo` (5); el archivo se guarda tal cual para poder contrastarlo con
 * el original.
 *
 * **Zonas**: una por departamento (24 departamentos + la Provincia
 * Constitucional del Callao), código `ZON-<cod_dep_inei>` (p. ej. `ZON-01`) y
 * nombre del departamento. Es la agrupación inicial: las zonas son datos de
 * negocio y se pueden renombrar o reagrupar después (`locations.manage`), por
 * eso el seed no pisa `is_active`.
 *
 * Idempotente: `upsert` por `code`/`ubigeo`.
 */
class UbigeoSeeder extends Seeder
{
    /** Archivo del padrón, relativo a la raíz del proyecto. */
    public const SOURCE = 'database/data/ubigeo.csv';

    /** Filas por sentencia `upsert` (el padrón tiene ~1877). */
    private const CHUNK = 500;

    public function run(): void
    {
        $rows = $this->rows();

        if ($rows === []) {
            $this->command?->warn('No se encontró '.self::SOURCE.'; no se siembran zonas ni ubicaciones.');

            return;
        }

        $now = now();

        DB::transaction(function () use ($rows, $now): void {
            $departments = [];

            foreach ($rows as $row) {
                $departments[$row['department_code']] ??= $row['department'];
            }

            DB::table('zones')->upsert(
                array_map(fn (string $name, string $code): array => [
                    'code' => "ZON-{$code}",
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $departments, array_keys($departments)),
                ['code'],
                ['name', 'updated_at'],
            );

            /** @var array<string, int> $zoneIds */
            $zoneIds = DB::table('zones')->pluck('id', 'code')->all();

            $locations = array_map(fn (array $row): array => [
                'ubigeo' => $row['ubigeo'],
                'department' => $row['department'],
                'province' => $row['province'],
                'district' => $row['district'],
                'zone_id' => $zoneIds["ZON-{$row['department_code']}"],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);

            foreach (array_chunk($locations, self::CHUNK) as $chunk) {
                DB::table('locations')->upsert(
                    $chunk,
                    ['ubigeo'],
                    ['department', 'province', 'district', 'zone_id', 'updated_at'],
                );
            }
        });
    }

    /**
     * Filas del padrón, sin cabecera.
     *
     * @return list<array{department_code: string, department: string, province: string, ubigeo: string, district: string}>
     */
    private function rows(): array
    {
        $path = base_path(self::SOURCE);

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $rows = [];

        while (($fields = fgetcsv($handle, escape: '')) !== false) {
            // La cabecera lleva BOM, así que se descarta por contenido: el primer
            // campo de una fila de datos es siempre el código numérico INEI.
            if (count($fields) < 6 || ! preg_match('/^\d{1,2}$/', trim((string) $fields[0]))) {
                continue;
            }

            $rows[] = [
                'department_code' => trim((string) $fields[0]),
                'department' => trim((string) $fields[1]),
                'province' => trim((string) $fields[3]),
                'ubigeo' => trim((string) $fields[4]),
                'district' => trim((string) $fields[5]),
            ];
        }

        fclose($handle);

        return $rows;
    }
}
