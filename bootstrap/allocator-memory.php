<?php

// Composer loads this before Laravel boots. Reserve memory only inside a deliberately
// opted-in CLI allocation. Other commands and web/queue processes register no handler.
if (PHP_SAPI !== 'cli' || getenv('BEAM_DEV_ALLOCATOR_MEMORY_DIAGNOSTIC') !== '1') {
    return;
}

$beamDevAllocator = match ($_SERVER['argv'][1] ?? null) {
    'splicewire:beam:dev:isolated-test-db' => 'isolated-test-db',
    'splicewire:beam:dev:walk-db' => ($_SERVER['argv'][2] ?? null) === 'create' ? 'walk-db create' : null,
    default => null,
};

if ($beamDevAllocator === null) {
    return;
}

$beamDevMemoryReserve = str_repeat("\0", 128 * 1024);
register_shutdown_function(static function () use (&$beamDevMemoryReserve, $beamDevAllocator): void {
    $beamDevMemoryReserve = '';
    $last = error_get_last();

    if (($last['type'] ?? null) !== E_ERROR
        || ! str_starts_with($last['message'], 'Allowed memory size of ')) {
        return;
    }

    fwrite(STDERR, "{$beamDevAllocator}: PHP memory limit exhausted before allocation completed. "
        .'Verify the scratch database state before retrying with php -d memory_limit=1G. '
        ."No database outcome was verified by this diagnostic.\n");
});
