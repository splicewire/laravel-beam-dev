<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Dev\Load\ContentionProbe;
use Splicewire\Beam\Dev\Load\LoadTargetSource;
use Splicewire\Beam\Dev\Load\Sample;
use Splicewire\Beam\Dev\Load\TargetRunner;
use Splicewire\Beam\Dev\Load\Timings;

/**
 * Drive one declared operation at a controlled concurrency and report honest latency percentiles,
 * query counts, error rate, and what the database server was carrying at the time.
 *
 * ## Why this exists
 *
 * Measured 2026-09-02 across the estate: there is no load test, benchmark, soak run, p99, or capacity
 * plan anywhere. A true zero, taken with controls. So "what degrades first, and does it degrade
 * gracefully" has never been asked of this system with an instrument, only reasoned about.
 *
 * ## Concurrency is real processes, not a simulation
 *
 * The parent spawns `--concurrency` copies of ITSELF in `--worker` mode via `proc_open`, each running
 * its share of the iterations, then merges their samples. PHP has no in-process concurrency worth
 * measuring against; a loop pretending to be eight clients would produce numbers describing nothing.
 *
 * `proc_open` rather than `symfony/process` deliberately: this package requires almost nothing
 * on purpose, and process spawning is not worth a dependency it can get from core.
 *
 * ## Two failures this command refuses to report as results
 *
 * **An unbound target source is an error, not an empty run.** Nothing binds {@see LoadTargetSource}
 * by default — the set of operations worth driving is the host's vocabulary, and beam-dev is
 * family-blind. Unbound, this FAILS and says so, because "no targets declared" and "no adapter
 * installed" rendering identically is the estate's signature defect.
 *
 * **A worker that died is not a fast worker.** A crashed child contributes no samples, which would
 * silently improve the percentiles of whatever survived. Every worker's exit code is checked and a
 * non-zero one fails the whole run.
 */
class LoadCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:load
        {target? : The target label to drive (omit to list what this host declares)}
        {--iterations=50 : Total runs across all workers}
        {--concurrency=1 : How many worker processes to run at once}
        {--connection= : Database connection to probe for contention}
        {--json : Emit the reading as JSON instead of a table}
        {--worker : INTERNAL — run one worker\'s share and print its samples as JSON}';

    protected $description = 'Drive a declared operation at a controlled concurrency and report latency percentiles, queries and contention';

    public function handle(Container $container, TargetRunner $runner, ContentionProbe $contention): int
    {
        if (! $container->bound(LoadTargetSource::class)) {
            // Deliberately a hard failure. See the class docblock.
            $this->error('No '.LoadTargetSource::class.' is bound.');
            $this->line('  beam-dev ships none by design — it cannot name this host\'s operations without');
            $this->line('  depending upward on the family it is a tool for. Bind an adapter that derives its');
            $this->line('  targets from the host\'s own registries, then re-run.');

            return self::FAILURE;
        }

        $targets = $container->make(LoadTargetSource::class)->targets();

        if ($targets === []) {
            // Distinct from the branch above, and the distinction is the whole point: this one means
            // the adapter IS installed and genuinely declares nothing.
            $this->warn('A target source is bound and declares no targets.');

            return self::SUCCESS;
        }

        $label = $this->argument('target');

        if ($label === null) {
            $this->line('Declared targets:');
            foreach (array_keys($targets) as $name) {
                $this->line('  '.$name);
            }

            return self::SUCCESS;
        }

        if (! isset($targets[$label])) {
            $this->error("No target labelled '{$label}'. Run without an argument to list them.");

            return self::FAILURE;
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $concurrency = max(1, (int) $this->option('concurrency'));

        if ($this->option('worker')) {
            $samples = $runner->run($targets[$label], $iterations);
            $this->output->writeln(json_encode(array_map(fn (Sample $s): array => $s->toArray(), $samples)));

            return self::SUCCESS;
        }

        $before = $contention->connections($this->option('connection'));
        [$samples, $peak] = $this->fanOut($label, $iterations, $concurrency, $contention);
        $after = $contention->connections($this->option('connection'));

        if ($samples === null) {
            return self::FAILURE;
        }

        $reading = Timings::of($samples)->summary() + [
            'target' => $label,
            'concurrency' => $concurrency,
            'db_connections_before' => $before,
            'db_connections_peak' => $peak,
            'db_connections_after' => $after,
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode($reading, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($reading as $key => $value) {
            $this->line(str_pad($key, 24).' '.($value ?? '—'));
        }

        if ($peak !== null && $concurrency > 1) {
            $this->newLine();
            $this->comment('Read the connection counts before the latency. At concurrency of 8 and up');
            $this->comment('against one local server, the number you are measuring is the cluster.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<Sample>|null, 1: int|null} samples (null on any worker failure), peak connections
     */
    private function fanOut(string $label, int $iterations, int $concurrency, ContentionProbe $contention): array
    {
        $share = (int) max(1, ceil($iterations / $concurrency));
        $procs = [];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        for ($i = 0; $i < $concurrency; $i++) {
            $cmd = [
                PHP_BINARY, base_path('artisan'), 'splicewire:beam:dev:load', $label,
                '--worker', '--iterations='.$share,
            ];

            $proc = proc_open($cmd, $descriptors, $pipes);

            if ($proc === false) {
                $this->error('Could not spawn worker '.$i);

                return [null, null];
            }

            $procs[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        // Sampled while the children are actually in flight — a count taken after they exit measures
        // the quiet that follows the run rather than the run.
        $peak = $contention->connections($this->option('connection'));

        $samples = [];

        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($p['pipes'][1]);
            $stderr = stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            $exit = proc_close($p['proc']);

            if ($exit !== 0) {
                // A dead worker contributes no samples, which would silently flatter every percentile
                // computed from the survivors.
                $this->error("Worker {$i} exited {$exit}: ".trim($stderr));

                return [null, $peak];
            }

            $decoded = json_decode(trim((string) $stdout), true);

            if (! is_array($decoded)) {
                $this->error("Worker {$i} produced no parseable samples.");

                return [null, $peak];
            }

            foreach ($decoded as $row) {
                $samples[] = Sample::fromArray($row);
            }
        }

        return [$samples, $peak];
    }
}
