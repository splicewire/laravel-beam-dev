<?php

namespace Splicewire\Beam\Dev\Load;

/**
 * Percentiles over a set of {@see Sample}s, by the **nearest-rank** method.
 *
 * ## Why the method is named here rather than assumed
 *
 * "p95" is not one definition. Nearest-rank, linear interpolation and the several exclusive/inclusive
 * variants disagree on small samples by more than the differences a tuning session is usually chasing
 * — so a number quoted without its method is not comparable to anything, including a later run of
 * itself. Nearest-rank is chosen because it always returns a value that was actually observed, which
 * is the honest thing for a latency reading: an interpolated p99 is a number no request ever took.
 *
 * Rank for percentile p over n sorted samples is `ceil(p / 100 * n)`, 1-indexed, clamped to `[1, n]`.
 *
 * ## Errors are excluded from latency and reported separately
 *
 * A failing request is usually fast, so folding errors into the latency set makes a degrading system
 * look like it is speeding up. {@see errorRate()} carries them instead, and a reading with a non-zero
 * error rate should be read as two facts, never averaged into one.
 */
final class Timings
{
    /** @param list<Sample> $samples */
    public function __construct(private readonly array $samples) {}

    /** @param list<Sample> $samples */
    public static function of(array $samples): self
    {
        return new self(array_values($samples));
    }

    public function count(): int
    {
        return count($this->samples);
    }

    public function errorCount(): int
    {
        return count(array_filter($this->samples, fn (Sample $s): bool => ! $s->ok()));
    }

    public function errorRate(): float
    {
        return $this->count() === 0 ? 0.0 : $this->errorCount() / $this->count();
    }

    /** Nanoseconds, nearest-rank. Null when there is nothing to rank — never 0, which reads as "instant". */
    public function percentileNs(float $percentile): ?int
    {
        $durations = $this->successfulDurations();

        if ($durations === []) {
            return null;
        }

        sort($durations);

        $rank = (int) ceil($percentile / 100 * count($durations));
        $rank = max(1, min($rank, count($durations)));

        return $durations[$rank - 1];
    }

    public function percentileMs(float $percentile): ?float
    {
        $ns = $this->percentileNs($percentile);

        return $ns === null ? null : round($ns / 1_000_000, 3);
    }

    /** Total queries across successful samples — the other half of a latency reading. */
    public function queries(): int
    {
        return array_sum(array_map(
            fn (Sample $s): int => $s->queries,
            array_filter($this->samples, fn (Sample $s): bool => $s->ok()),
        ));
    }

    public function queriesPerRun(): ?float
    {
        $ok = count($this->successfulDurations());

        return $ok === 0 ? null : round($this->queries() / $ok, 2);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'runs' => $this->count(),
            'errors' => $this->errorCount(),
            'error_rate' => round($this->errorRate(), 4),
            'p50_ms' => $this->percentileMs(50),
            'p95_ms' => $this->percentileMs(95),
            'p99_ms' => $this->percentileMs(99),
            'queries_per_run' => $this->queriesPerRun(),
            'percentile_method' => 'nearest-rank',
        ];
    }

    /** @return list<int> */
    private function successfulDurations(): array
    {
        return array_values(array_map(
            fn (Sample $s): int => $s->durationNs,
            array_filter($this->samples, fn (Sample $s): bool => $s->ok()),
        ));
    }
}
