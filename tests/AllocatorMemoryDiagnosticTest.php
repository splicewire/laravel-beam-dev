<?php

namespace Splicewire\Beam\Dev\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AllocatorMemoryDiagnosticTest extends TestCase
{
    private function runFixture(string|array $command, string $mode, string $memory, bool $optIn): Process
    {
        $process = new Process([
            PHP_BINARY,
            '-d', "memory_limit={$memory}",
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            __DIR__.'/fixtures/allocator-memory.php',
            ...(array) $command,
            $mode,
        ], env: ['BEAM_DEV_ALLOCATOR_MEMORY_DIAGNOSTIC' => $optIn ? '1' : '0', 'XDEBUG_MODE' => 'off']);
        $process->run();

        return $process;
    }

    public function test_an_opted_in_allocator_oom_names_the_command_without_masking_failure(): void
    {
        $process = $this->runFixture('splicewire:beam:dev:isolated-test-db', 'oom', '16M', true);

        $this->assertSame(255, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('isolated-test-db: PHP memory limit exhausted', $process->getErrorOutput());
        $this->assertStringContainsString('memory_limit=1G', $process->getErrorOutput());

        $walk = $this->runFixture(['splicewire:beam:dev:walk-db', 'create'], 'oom', '16M', true);
        $this->assertSame(255, $walk->getExitCode());
        $this->assertStringContainsString('walk-db create: PHP memory limit exhausted', $walk->getErrorOutput());
    }

    public function test_normal_memory_and_unrelated_or_unrequested_failures_keep_their_original_output(): void
    {
        $command = 'splicewire:beam:dev:isolated-test-db';
        $normal = $this->runFixture($command, 'oom', '128M', true);
        $this->assertSame(0, $normal->getExitCode());
        $this->assertSame("fixture completed\n", $normal->getOutput());
        $this->assertSame('', $normal->getErrorOutput());

        foreach ([[$command, 'oom', false], ['queue:work', 'oom', true], [['splicewire:beam:dev:walk-db', 'drop'], 'oom', true], [$command, 'fatal', true]] as [$verb, $mode, $marker]) {
            $process = $this->runFixture($verb, $mode, '16M', $marker);
            $this->assertSame(255, $process->getExitCode());
            $this->assertSame('', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        }
    }
}
