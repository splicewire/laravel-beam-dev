<?php

namespace Splicewire\Beam\Dev\Runs;

/**
 * The **completion witness** for a test run: given the run's captured output and its exit code, did the
 * suite actually finish, and does its own summary agree with the code it exited on?
 *
 * ## Why an exit code is not the answer
 *
 * A suite can stop partway, print the failures it had so far, and leave output that reads exactly like an
 * ordinary result. Four causes are on record in this estate, and they do not share an exit code:
 *
 * | cause | exit |
 * |---|---|
 * | PHP dying on `memory_limit` mid-suite | **0** |
 * | the runner piped through `head` — `SIGPIPE` tears the run down | **0** |
 * | a paratest worker segfaulting under `--parallel` | 1 (measured 3/3) |
 * | the `artisan test` wrapper killed, leaving an orphaned `pest` child | varies |
 *
 * So **neither the exit code alone nor the failure list alone tells you the suite finished.** The two
 * exit-0 causes defeat any automated gate; all four defeat a human reading a log. The one thing every
 * finished run of every supported runner emits is a **summary line**, and that is what this class looks
 * for — {@see SUMMARIES}.
 *
 * ## Why this may exit non-zero, when almost nothing in this estate may
 *
 * The fleet rule is that a check whose answer depends on the host must not throw. *"Did this invocation
 * emit a summary line?"* is a fact about **the run**: no host composition, no registry contents and no
 * config key can turn a truncated run into a finished one. It has the same answer everywhere, so a hard
 * failure is legitimate here rather than merely convenient.
 *
 * ## Provenance
 *
 * This is a lift, not an invention. `~/Herd/splicewire-app/scripts/test-isolated-db.sh:89-96` has done
 * exactly this since 2026-08-27 and works; the gap it leaves is that it is one host's private shell
 * script while 116 other roots have nothing. The converse check ({@see DISAGREES}) is new — nothing in
 * the estate did it, and it is nearly free once the summary is being parsed.
 */
class RunWitness
{
    /** The run finished and its summary agrees with its exit code. */
    public const FINISHED = 'finished';

    /** No summary line: the run did not finish, whatever it exited on. */
    public const TRUNCATED = 'truncated';

    /** A summary IS present, and it contradicts the exit code. */
    public const DISAGREES = 'disagrees';

    /**
     * The summary shapes a finished run emits, one per supported runner posture. All are anchored to a
     * line start so a failure message quoting the word `Tests:` cannot forge one.
     *
     * `No tests executed!` is deliberately a summary: a run that selected nothing DID finish, and
     * conflating "selected nothing" with "died partway" is the same category error this class exists to
     * prevent — one is an over-narrow filter, the other is a broken harness.
     *
     * @var list<string>
     */
    public const SUMMARIES = [
        '/^\s*Tests:\s+\d+/m',          // pest, and phpunit's failure summary
        '/^OK \(\d+ test/m',            // phpunit, all green
        '/^OK, but /m',                 // phpunit, green with incomplete/skipped/risky
        '/^No tests executed!/m',       // phpunit, an empty selection — finished, just empty
        '/^\s*Tests:\s+No tests/m',     // pest, an empty selection
    ];

    /**
     * Counts pulled out of a summary line, in the **two dialects the same `Tests:` line comes in**:
     * phpunit writes `Failed: 3` / `Errors: 1`, pest writes `18 failed` / `2 errored`. Both patterns are
     * applied to every summary; no runner uses both, so they cannot double-count.
     *
     * ⚠️ The pest dialect was missing from the first version, and **only a live run found it**. Every
     * unit fixture used the phpunit spelling, so `Tests: 18 failed, 28 passed` parsed as ZERO failures
     * and the witness reported a contradiction against pest's honest exit 2 — a false positive, in the
     * check whose entire job is telling a real result from a fake one. The fixtures were internally
     * consistent and wrong about the runner the flagship actually uses.
     *
     * Only what means *"this run failed"* is read. `Skipped`, `Incomplete`, `Risky` and `Warnings` do not
     * contradict a zero exit, and counting them would make this instrument wrong in the noisy direction.
     *
     * @var list<string>
     */
    public const FAILURE_PATTERNS = [
        '/\b(?:Failed|Failures|Errors):\s*(\d+)/i',   // phpunit
        '/\b(\d+)\s+(?:failed|errored)\b/i',         // pest
    ];

    public function __construct(
        public string $output,
        public int $exitCode,
    ) {}

    /** One of {@see FINISHED} / {@see TRUNCATED} / {@see DISAGREES}. */
    public function verdict(): string
    {
        if (! $this->hasSummary()) {
            return self::TRUNCATED;
        }

        $failures = $this->failureCount();

        if ($failures === null) {
            return self::FINISHED;
        }

        // Both directions are a disagreement, and the first is the dangerous one: a summary reporting
        // failures under a zero exit is a green build over a red suite.
        if (($failures > 0) !== ($this->exitCode !== 0)) {
            return self::DISAGREES;
        }

        return self::FINISHED;
    }

    public function finished(): bool
    {
        return $this->verdict() === self::FINISHED;
    }

    /** Did any supported runner's summary shape appear at the start of a line? */
    public function hasSummary(): bool
    {
        foreach (self::SUMMARIES as $pattern) {
            if (preg_match($pattern, $this->output) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Failures + errors as the summary itself reports them, or **null when there is no `Tests:` line to
     * read them from** — phpunit's `OK (...)` shape, or a truncated run.
     *
     * A `Tests:` line that names no failure count counts **zero**, not null: pest and phpunit both omit
     * the failure clause entirely when there is none, so `Tests: 415 passed` and
     * `Tests: 3, Assertions: 3, Skipped: 1` are positive statements that nothing failed. Null is reserved
     * for *"there is nothing here to cross-check"*, which is a different fact and must not be folded into
     * a counted zero — that distinction is what stops the converse check inventing a disagreement out of
     * a summary shape it cannot parse.
     */
    public function failureCount(): ?int
    {
        if (preg_match('/^\s*Tests:\s+.*$/m', $this->output, $match) !== 1) {
            return null;
        }

        $total = 0;
        foreach (self::FAILURE_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $match[0], $counts) > 0) {
                $total += array_sum(array_map('intval', $counts[1]));
            }
        }

        return $total;
    }

    /**
     * The operator-facing explanation for a non-finished verdict, naming the causes by hand rather than
     * leaving the reader to recognise the signature. Empty string when the run finished.
     */
    public function explanation(): string
    {
        return match ($this->verdict()) {
            self::TRUNCATED => 'NO SUMMARY LINE — this run did not finish, and its exit code ('
                .$this->exitCode.') is not evidence either way. Do not read the failure list as a '
                .'complete result. Known causes: PHP dying on memory_limit mid-suite (exits 0); the '
                .'runner piped through `head`, whose SIGPIPE tears the run down (exits 0); a paratest '
                .'worker segfaulting under --parallel (exits 1); an orphaned `pest` child after the '
                .'`artisan test` wrapper was killed. Re-run with the whole output redirected to a file.',
            self::DISAGREES => 'THE SUMMARY CONTRADICTS THE EXIT CODE — it reports '
                .$this->failureCount().' failure(s)/error(s) and the process exited '.$this->exitCode
                .'. One of the two is lying; trust neither until you know which.',
            default => '',
        };
    }
}
