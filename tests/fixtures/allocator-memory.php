<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

switch ($argv[count($argv) - 1] ?? '') {
    case 'oom':
        str_repeat('x', 32 * 1024 * 1024);
        break;
    case 'fatal':
        beam_dev_fixture_missing_function();
        break;
}

fwrite(STDOUT, "fixture completed\n");
