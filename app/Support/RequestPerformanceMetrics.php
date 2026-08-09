<?php

namespace App\Support;

final class RequestPerformanceMetrics
{
    private int $queryCount = 0;

    private float $queryMilliseconds = 0.0;

    private int $storageCallCount = 0;

    private float $storageMilliseconds = 0.0;

    /** @var array<int, array{time_ms: float, sql: string}> */
    private array $slowQueries = [];

    public function reset(): void
    {
        $this->queryCount = 0;
        $this->queryMilliseconds = 0.0;
        $this->storageCallCount = 0;
        $this->storageMilliseconds = 0.0;
        $this->slowQueries = [];
    }

    public function recordQuery(string $sql, float $milliseconds): void
    {
        $this->queryCount++;
        $this->queryMilliseconds += $milliseconds;

        if ($milliseconds >= 100.0) {
            $this->slowQueries[] = [
                'time_ms' => round($milliseconds, 2),
                'sql' => str($sql)->squish()->limit(300)->toString(),
            ];
        }
    }

    public function recordStorageCall(float $milliseconds): void
    {
        $this->storageCallCount++;
        $this->storageMilliseconds += $milliseconds;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'query_count' => $this->queryCount,
            'db_ms' => round($this->queryMilliseconds, 2),
            'slow_queries' => array_slice($this->slowQueries, 0, 5),
            'storage_call_count' => $this->storageCallCount,
            'storage_ms' => round($this->storageMilliseconds, 2),
        ];
    }
}
