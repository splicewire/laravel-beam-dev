<?php

namespace Splicewire\Beam\Dev\Load;

/**
 * One measured execution of a {@see LoadTarget}.
 *
 * Duration is in **nanoseconds** and comes from `hrtime(true)`, deliberately. `microtime()` is a
 * float of seconds and loses resolution as the epoch grows; a `timestamp` column is worse still.
 * This estate has a live instance of the latter — `rushing/laravel-request-logs` declares
 * `timestamp('request_at')` at precision 0 and exposes a `duration_ms` accessor, so it returns
 * milliseconds that are always multiples of 1000 and never looks broken. An integer nanosecond
 * counter cannot fail that way.
 */
final class Sample
{
    public function __construct(
        public readonly int $durationNs,
        public readonly int $queries,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /** @return array{ns: int, queries: int, error: string|null} */
    public function toArray(): array
    {
        return ['ns' => $this->durationNs, 'queries' => $this->queries, 'error' => $this->error];
    }

    /** @param array{ns: int, queries: int, error?: string|null} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['ns'], $row['queries'], $row['error'] ?? null);
    }
}
