<?php

namespace Splicewire\Beam\Dev\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Splicewire\Beam\Dev\Runs\RunWitness;

/**
 * The completion witness (beam-facade ticket 169).
 *
 * Every fixture below is a real captured shape, not an invented one: the pest and phpunit summaries were
 * taken from runs in this estate on 2026-08-29, and the truncated shapes are the four causes on record —
 * a memory death, a `head` SIGPIPE, a paratest worker segfault, and an orphaned `pest` child.
 *
 * Plain PHPUnit, no application boot: the witness is a pure value over (output, exit code), and giving it
 * a container would only add a provider list to get wrong.
 */
class RunWitnessTest extends BaseTestCase
{
    public function test_it_accepts_a_pest_summary_as_a_finished_run(): void
    {
        $witness = new RunWitness("  Tests:    415 passed (1361 assertions)\n  Duration: 9.18s\n", 0);

        $this->assertTrue($witness->finished());
        $this->assertSame(RunWitness::FINISHED, $witness->verdict());
        $this->assertSame(0, $witness->failureCount());
    }

    public function test_it_accepts_phpunits_ok_line_which_carries_no_counts_to_cross_check(): void
    {
        $witness = new RunWitness("OK (13 tests, 30 assertions)\n", 0);

        $this->assertTrue($witness->finished());
        // null, not 0: an absent count and a counted zero are different facts, and folding them would
        // manufacture a disagreement on any runner whose summary omits the numbers.
        $this->assertNull($witness->failureCount());
    }

    public function test_it_accepts_the_ok_but_line_and_an_empty_selection_as_finished(): void
    {
        $this->assertTrue((new RunWitness("OK, but there were issues!\nTests: 3, Assertions: 3, Skipped: 1.\n", 0))->finished());
        $this->assertTrue((new RunWitness("No tests executed!\n", 0))->finished());
    }

    public function test_it_reads_failures_and_errors_together_out_of_a_summary(): void
    {
        $witness = new RunWitness("Tests: 1644, Assertions: 4198, Errors: 1, Skipped: 2.\n", 2);

        $this->assertSame(1, $witness->failureCount());
        $this->assertTrue($witness->finished(), '1 failure under a non-zero exit is agreement, not a contradiction');
    }

    public function test_it_does_not_count_skips_incompletes_or_risky_tests_as_failures(): void
    {
        $witness = new RunWitness("Tests: 10, Assertions: 20, Skipped: 3, Incomplete: 2, Risky: 1.\n", 0);

        $this->assertSame(0, $witness->failureCount());
        $this->assertTrue($witness->finished());
    }

    /**
     * ⚠️ The regression test for a false positive **only a live run found**. The flagship runs pest,
     * whose summary spells failures `18 failed` and not `Failed: 18`; every fixture above was written in
     * the phpunit dialect, so the parser read zero, disagreed with pest's honest exit 2, and cried wolf
     * in the one check whose job is telling a real result from a fake one. Real captured line,
     * ~/Herd/splicewire-app, 2026-08-29.
     */
    public function test_it_reads_pests_own_failure_dialect_and_not_only_phpunits(): void
    {
        $witness = new RunWitness("  Tests:    18 failed, 28 passed (58 assertions)\n  Duration: 8.08s\n", 2);

        $this->assertSame(18, $witness->failureCount());
        $this->assertTrue($witness->finished(), '18 failures under exit 2 is agreement');
    }

    public function test_it_reads_pests_errored_dialect_too(): void
    {
        $witness = new RunWitness("  Tests:    2 errored, 1 failed, 40 passed (90 assertions)\n", 1);

        $this->assertSame(3, $witness->failureCount());
    }

    /* ---------------- the four truncation causes ---------------- */

    public function test_it_reports_a_memory_death_as_truncated_even_though_it_exits_zero(): void
    {
        $output = "  FAILED  Tests\\Feature\\ThingTest > it works\n"
            ."PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted\n";

        $witness = new RunWitness($output, 0);

        $this->assertSame(RunWitness::TRUNCATED, $witness->verdict());
        $this->assertFalse($witness->finished());
        $this->assertStringContainsString('NO SUMMARY LINE', $witness->explanation());
        $this->assertStringContainsString('memory_limit', $witness->explanation());
    }

    public function test_it_reports_a_paratest_worker_crash_as_truncated_even_though_it_exits_one(): void
    {
        // Measured 3/3 at ~/Herd/splicewire-app, 2026-08-28: this shape exits 1, not 0. The ticket's own
        // "and exits 0" premise never held — which is exactly why the summary line, and not the exit
        // code, is the witness.
        $output = "WorkerCrashedException.php line 41\nExit Code: 139(Segmentation violation)\n\nUsage:\n  paratest [options]\n";

        $witness = new RunWitness($output, 1);

        $this->assertSame(RunWitness::TRUNCATED, $witness->verdict());
        $this->assertStringContainsString('segfault', $witness->explanation());
    }

    public function test_it_reports_a_head_truncated_run_as_truncated(): void
    {
        $output = "   PASS  Tests\\Unit\\OneTest\n   PASS  Tests\\Unit\\TwoTest\n";

        $this->assertSame(RunWitness::TRUNCATED, (new RunWitness($output, 0))->verdict());
    }

    public function test_it_is_not_fooled_by_the_word_tests_inside_a_failure_message(): void
    {
        // Anchored to a line start on purpose: a diff or an assertion message can quote anything.
        $output = "  FAILED  it parses a log\n  Failed asserting that 'Tests: 40 passed' matches expected ''\n";

        $this->assertSame(RunWitness::TRUNCATED, (new RunWitness($output, 1))->verdict());
    }

    /* ---------------- the converse: a summary that contradicts the exit code ---------------- */

    public function test_it_refuses_a_zero_exit_when_the_summary_reports_failures(): void
    {
        $witness = new RunWitness("Tests: 40, Assertions: 100, Failed: 3.\n", 0);

        $this->assertSame(RunWitness::DISAGREES, $witness->verdict());
        $this->assertFalse($witness->finished());
        $this->assertStringContainsString('CONTRADICTS', $witness->explanation());
    }

    public function test_it_refuses_a_non_zero_exit_when_the_summary_reports_a_clean_suite(): void
    {
        $this->assertSame(RunWitness::DISAGREES, (new RunWitness("Tests: 40, Assertions: 100, Skipped: 1.\n", 1))->verdict());
    }

    public function test_it_says_nothing_about_a_disagreement_it_cannot_see(): void
    {
        // phpunit's OK line has no counts, so there is nothing to contradict. Reporting a disagreement
        // here would be inventing one out of a missing number.
        $this->assertSame(RunWitness::FINISHED, (new RunWitness("OK (13 tests, 30 assertions)\n", 0))->verdict());
    }

    /**
     * ⚠️ The witness REFUSED A GREEN RUN at 13 of the estate's ~21 Herd roots.
     *
     * `laravel/pao` replaces the human summary with `json_encode($result)` on STDOUT, so a complete,
     * passing run emits neither `Tests:` nor `OK (` — and this class reported TRUNCATED, which is a false
     * negative in the one instrument whose job is telling a real result from a fake one. It survived
     * because the witness was built and proven at `splicewire-app`, which is NOT one of the 13.
     */
    public function test_it_accepts_the_pao_json_summary_as_a_finished_run(): void
    {
        $green = '{"tool":"pest","result":"passed","tests":20,"passed":20}'."\n";

        $this->assertSame(RunWitness::FINISHED, (new RunWitness($green, 0))->verdict());
    }

    public function test_the_pao_result_field_still_carries_the_exit_code_disagreement(): void
    {
        // pao carries no counts to add up, so the verdict IS the `result` field. Reading it keeps the
        // check this class exists for: a green summary under a non-zero exit, and a failed summary under
        // a zero exit, are both disagreements.
        $green = '{"tool":"pest","result":"passed","tests":20,"passed":20}'."\n";
        $red = '{"tool":"pest","result":"failed","tests":20,"passed":18}'."\n";

        $this->assertSame(RunWitness::DISAGREES, (new RunWitness($green, 1))->verdict());
        $this->assertSame(RunWitness::DISAGREES, (new RunWitness($red, 0))->verdict());
        $this->assertSame(RunWitness::FINISHED, (new RunWitness($red, 1))->verdict());
    }

    public function test_prose_quoting_the_pao_shape_cannot_forge_a_summary(): void
    {
        // Anchored to a line start, like every other shape, so a failure message that happens to quote
        // the JSON does not manufacture a completion.
        $quoted = 'FAILED: expected {"tool":"pest","result":"passed"} but the run died'."\n";

        $this->assertSame(RunWitness::TRUNCATED, (new RunWitness($quoted, 1))->verdict());
    }
}
