<?php

namespace Src\Reporting\Services;

use Src\Shared\Contracts\ReportSource;

/**
 * Reportes disponibles, los aporte el contexto que los aporte.
 */
final class ReportCatalog
{
    /**
     * @param  iterable<ReportSource>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
    ) {}

    public function find(string $key): ?ReportSource
    {
        foreach ($this->sources as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, title: string, columns: list<string>, statuses: list<string>}>
     */
    public function describe(): array
    {
        $reports = [];

        foreach ($this->sources as $source) {
            $reports[] = [
                'key' => $source->key(),
                'title' => $source->title(),
                'columns' => array_values($source->columns()),
                'statuses' => $source->statuses(),
            ];
        }

        return $reports;
    }
}
