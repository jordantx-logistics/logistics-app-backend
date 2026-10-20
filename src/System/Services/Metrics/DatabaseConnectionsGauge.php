<?php

namespace Src\System\Services\Metrics;

use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Conexiones abiertas en el servidor de base de datos.
 *
 * Se consulta `Threads_connected` (contador global del servidor) en lugar de
 * `information_schema.PROCESSLIST` porque este último solo ve las conexiones
 * del propio usuario sin el privilegio `PROCESS`.
 */
final class DatabaseConnectionsGauge
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Devuelve `null` cuando el motor (SQLite en tests) o el servidor no
     * permiten leer el contador: no es un fallo del scrape.
     */
    public function value(): ?float
    {
        $connection = $this->database->connection();

        if ($connection->getDriverName() !== 'mysql') {
            return null;
        }

        try {
            // `SHOW` no admite el protocolo de sentencias preparadas, así que se
            // ejecuta con PDO directamente en vez de con `DB::select()`.
            $row = $connection->getPdo()
                ->query("SHOW GLOBAL STATUS WHERE Variable_name = 'Threads_connected'")
                ?->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        return isset($row['Value']) ? (float) $row['Value'] : null;
    }
}
