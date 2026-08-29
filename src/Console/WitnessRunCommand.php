<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Dev\Runs\RunWitness;

/**
 * Run a test suite and refuse to report a pass for a run that did not finish.
 *
 * The sibling of {@see IsolatedTestDbCommand} at the other end of the same job: that one makes sure the
 * run has a database nobody else is using, this one makes sure the result you read is a result. Between
 * them they cover the two ways a suite gets measured wrong for reasons that have nothing to do with the
 * code — a neighbour's reset, and a harness that stopped early.
 *
 * ## What it does
 *
 * It spawns the runner, streams its output to your terminal **and** to a log file, waits for it, and puts
 * the pair (output, exit code) through {@see RunWitness}. Three outcomes:
 *
 *  - **finished** — a summary line is present and agrees with the exit code. The runner's own exit code is
 *    returned unchanged; this command adds nothing to a run that behaved.
 *  - **truncated** — no summary line. **Exit 1**, whatever the runner exited on, with the four known
 *    causes named in the output.
 *  - **disagrees** — a summary IS present and contradicts the exit code. **Exit 1**.
 *
 * ## Two implementation constraints that are not stylistic
 *
 * **Nothing here pipes the runner through `head`, `tail` or `grep`.** One of the four causes IS that
 * pipeline: `head` closing the pipe sends `SIGPIPE`, the run is torn down, and you are left with a
 * partial failure list, no summary and exit 0 — indistinguishable from a memory death. An instrument
 * whose implementation can produce the fault it detects is not an instrument.
 *
 * **The exit code is read off the process, never off a pipeline.** Reading `$?` after `| tail` reports the
 * pipeline's status. That habit is how a paratest crash — measured at exit 1, three times out of three —
 * gets written down as exit 0, which is a false report about the very signature this command exists for.
 *
 * ## Provenance and scope
 *
 * Lifted from `~/Herd/splicewire-app/scripts/test-isolated-db.sh:89-96`, which has done the summary check
 * since 2026-08-27 and works. The gap it left is that it is one host's private shell script: measured
 * 2026-08-28, no other root in the estate carried an equivalent, and `AGENTS.md`'s remedy for the whole
 * class was a paragraph a human has to remember. The converse check is new — nothing in the estate did
 * it, and it costs one comparison once the summary is being parsed.
 *
 * `--assert` runs no suite and reads a log you already have, which is the seam for a CI step or a
 * Makefile that must keep its own runner invocation.
 */
class WitnessRunCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:witness-run
        {runner?* : The runner and its arguments (default: the configured runner)}
        {--log= : Where to write the full captured output (default: a session-scoped temp file)}
        {--assert= : Skip running anything; witness this existing log file instead}
        {--exit-code=0 : With --assert, the exit code that run reported}
        {--keep-log : Do not delete the log on a finished run}';

    protected $description = 'Run a test suite and fail when the run did not finish, whatever it exited on';

    public function handle(): int
    {
        if (is_string($log = $this->option('assert')) && $log !== '') {
            return $this->assertLog($log, (int) $this->option('exit-code'));
        }

        $command = $this->runnerCommand();
        $log = (string) ($this->option('log') ?: $this->defaultLog());

        $this->components->info('Running: '.implode(' ', $command));
        $this->line('  <fg=gray>full output → '.$log.'</>');
        $this->newLine();

        [$output, $exitCode] = $this->spawn($command, $log);

        return $this->report(new RunWitness($output, $exitCode), $log, ranIt: true);
    }

    /**
     * The runner to spawn: whatever the caller passed, else `config('beam.dev.runner')`, else the
     * project's own `artisan test`. Deliberately not sniffed from what is installed — a wrong guess here
     * runs the wrong suite and reports confidently about it.
     *
     * @return list<string>
     */
    private function runnerCommand(): array
    {
        $passed = array_values(array_map('strval', (array) $this->argument('runner')));

        if ($passed !== []) {
            return $passed;
        }

        $configured = (array) config('beam.dev.runner', []);

        if ($configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        return [PHP_BINARY, base_path('artisan'), 'test'];
    }

    /**
     * Spawn the runner, tee its combined output to the terminal and the log, and return
     * `[captured output, the PROCESS's exit code]`.
     *
     * `proc_open` rather than a shell string: a shell would need a pipeline to tee, and every pipeline in
     * this neighbourhood is a way to lose either the run or its status.
     *
     * @param  list<string>  $command
     * @return array{0: string, 1: int}
     */
    private function spawn(array $command, string $log): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, base_path());

        if (! is_resource($process)) {
            // Could not spawn at all — no output, and no summary, so the witness reports TRUNCATED,
            // which is the honest verdict: nothing ran.
            return ['', 127];
        }

        $handle = @fopen($log, 'w');
        $captured = '';

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while (true) {
            $open = false;

            foreach ($pipes as $pipe) {
                if (feof($pipe)) {
                    continue;
                }

                $open = true;
                $chunk = (string) fread($pipe, 8192);

                if ($chunk !== '') {
                    $captured .= $chunk;
                    $this->output->write($chunk);
                    if (is_resource($handle)) {
                        fwrite($handle, $chunk);
                    }
                }
            }

            if (! $open) {
                break;
            }

            // A short sleep rather than a blocking read: both pipes must be drained concurrently or a
            // runner that fills stderr while we block on stdout deadlocks — and a deadlocked witness is
            // worse than no witness.
            usleep(10_000);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        if (is_resource($handle)) {
            fclose($handle);
        }

        return [$captured, proc_close($process)];
    }

    private function assertLog(string $log, int $exitCode): int
    {
        if (! is_file($log)) {
            $this->components->error("No such log file: {$log}");

            return self::FAILURE;
        }

        return $this->report(new RunWitness((string) file_get_contents($log), $exitCode), $log, ranIt: false);
    }

    private function report(RunWitness $witness, string $log, bool $ranIt): int
    {
        $this->newLine();

        if ($witness->finished()) {
            $this->components->info('Run finished — summary line present'
                .($witness->failureCount() === null ? '.' : ', reporting '.$witness->failureCount().' failure(s)/error(s).'));

            if ($ranIt && ! $this->option('keep-log') && is_file($log)) {
                @unlink($log);
            }

            return $witness->exitCode;
        }

        $this->components->error($witness->explanation());

        if (is_file($log)) {
            $this->line('  <fg=gray>the full output is kept at '.$log.'</>');
        }

        return self::FAILURE;
    }

    /**
     * A session-scoped log path. pid-keyed on purpose: this estate shares one temp dir across concurrent
     * agent sessions, and a fixed scratch name once accounted for 29 of a 35-failure delta.
     */
    private function defaultLog(): string
    {
        return sys_get_temp_dir().'/beam-dev-run-'.getmypid().'-'.bin2hex(random_bytes(4)).'.log';
    }
}
