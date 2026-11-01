<?php

namespace Src\System\Services\Metrics;

use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Retraso de la réplica, leído en la propia réplica: a través de ProxySQL la consulta
 * podría caer en el primary justo cuando el dato importa.
 */
final class ReplicationLagGauge
{
    /** Valor cuando la replicación está parada: por encima del tope de 30 s de la alerta. */
    private const STOPPED = 3600.0;

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    public function value(): ?float
    {
        if (! config('database.connections.replica_monitor.host')) {
            return null;
        }

        try {
            $connection = $this->database->connection('replica_monitor');
            $row = $connection->getPdo()->query('SHOW REPLICA STATUS')?->fetch(PDO::FETCH_ASSOC);
            $connection->disconnect();
        } catch (Throwable) {
            return null;
        }

        if ($row === false || $row === null || $row['Seconds_Behind_Source'] === null) {
            return self::STOPPED;
        }

        return (float) $row['Seconds_Behind_Source'];
    }
}
