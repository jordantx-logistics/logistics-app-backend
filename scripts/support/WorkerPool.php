<?php

/**
 * Procesos concurrentes para las comprobaciones de `scripts/*-concurrency-check.php`.
 *
 * Cada proceso es el propio script con `--worker`: lee una orden JSON por línea y
 * responde con otra. Arrancan una sola vez, porque arrancar Laravel es mucho más
 * lento que la ventana de carrera que se quiere provocar.
 */
final class WorkerPool
{
    /** @var list<array{process: resource, stdin: resource, stdout: resource}> */
    private array $workers = [];

    public function __construct(string $script, int $size)
    {
        for ($i = 0; $i < $size; $i++) {
            $process = proc_open(
                [PHP_BINARY, $script, '--worker'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
                $pipes,
            );

            $this->workers[] = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1]];
        }

        foreach ($this->workers as $worker) {
            if (trim((string) fgets($worker['stdout'])) !== 'ready') {
                throw new RuntimeException('Un proceso no arrancó.');
            }
        }
    }

    /**
     * Entrega una orden a cada proceso y espera todas las respuestas.
     *
     * @param  list<array<string, mixed>>  $commands
     * @return list<array{ok: bool, code?: string}>
     */
    public function run(array $commands): array
    {
        foreach ($commands as $i => $command) {
            fwrite($this->workers[$i]['stdin'], json_encode($command)."\n");
        }

        $replies = [];

        foreach (array_keys($commands) as $i) {
            $replies[] = json_decode((string) fgets($this->workers[$i]['stdout']), true, flags: JSON_THROW_ON_ERROR);
        }

        return $replies;
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            fclose($worker['stdin']);
            fclose($worker['stdout']);
            proc_close($worker['process']);
        }
    }

    /**
     * Bucle de un proceso.
     *
     * @param  callable(array<string, mixed>): array{ok: bool, code?: string}  $execute
     */
    public static function serve(callable $execute): void
    {
        echo "ready\n";

        while (($line = fgets(STDIN)) !== false) {
            echo json_encode($execute(json_decode($line, true, flags: JSON_THROW_ON_ERROR)))."\n";
        }
    }
}
