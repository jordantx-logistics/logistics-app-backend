<?php

namespace Src\System\UseCases;

use Src\System\Services\DependencyHealthChecker;

class CheckSystemHealthUseCase
{
    public function __construct(
        private readonly DependencyHealthChecker $checker,
    ) {}

    /**
     * @return array{status: string, checks: array<string, array{status: string, detail: string, latency_ms: float}>}
     */
    public function execute(): array
    {
        $checks = $this->checker->check();

        $down = array_filter($checks, fn (array $check) => $check['status'] !== 'up');

        return [
            'status' => $down === [] ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }
}
