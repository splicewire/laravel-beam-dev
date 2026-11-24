<?php

namespace Splicewire\Beam\Dev\Databases;

use Dotenv\Parser\Parser;
use Illuminate\Database\Connection;
use RuntimeException;

final class WalkDatabaseTarget
{
    private string $room;

    private string $socket;

    private int $port;

    private string $user;

    public function __construct(?string $room, ?string $socket, ?string $port, ?string $user)
    {
        $r = $room === null ? false : realpath($room);
        $s = $socket === null ? false : realpath($socket);
        if ($r === false || $s === false || $r === '/' || ! is_dir($r) || ! is_dir($s) || (fileperms($r) & 0077) !== 0
            || ! preg_match('#^/[A-Za-z0-9._/-]+$#D', $s)
            || ! str_starts_with($s, $r.'/') || (fileperms($s) & 0077) !== 0
            || fileowner($r) !== fileowner($s)) {
            throw new RuntimeException('Provide a private Unix socket directory inside the owned walk room.');
        }
        if ($port === null || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || $user === null || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $user)) {
            throw new RuntimeException('Provide a numeric PostgreSQL port and a lowercase PostgreSQL role.');
        }

        $this->room = $r;
        $this->socket = $s;
        $this->port = (int) $port;
        $this->user = $user;
    }

    /** @return array{driver: string, host: string, port: int, username: string, password: string, database: string} */
    public function connection(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->socket,
            'port' => $this->port,
            'username' => $this->user,
            'password' => '',
            'database' => 'postgres',
        ];
    }

    public function verify(Connection $connection): void
    {
        $facts = (array) $connection->selectOne(
            "select inet_server_addr() is null as socket_only, current_setting('listen_addresses') as listen_addresses, current_setting('data_directory') as data_directory",
        );
        $data = realpath((string) ($facts['data_directory'] ?? ''));
        if (! in_array($facts['socket_only'] ?? false, [true, 1, '1', 't'], true)
            || ($facts['listen_addresses'] ?? null) !== '' || $data === false || ! is_dir($data)
            || ! str_starts_with($data, $this->room.'/')) {
            throw new RuntimeException('The target is not a socket-only PostgreSQL server owned by this room.');
        }
    }

    public function envPath(string $path): string
    {
        $parent = realpath(dirname($path));
        $file = $parent.'/.env';
        $stat = $parent === false ? false : @lstat($file);
        if ($parent === false || ! str_starts_with($parent, $this->room.'/') || basename($path) !== '.env'
            || $stat === false || ! is_file($file) || is_link($file) || $stat['nlink'] !== 1
            || $stat['uid'] !== fileowner($this->room)) {
            throw new RuntimeException('The env target must be a regular owned scratch app .env inside the walk room.');
        }

        return $file;
    }

    public function writeEnv(string $path, string $name): void
    {
        $path = $this->envPath($path);
        $original = file_get_contents($path);
        if ($original === false) {
            throw new RuntimeException('Could not read the scratch app env file.');
        }

        $values = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $this->socket,
            'DB_PORT' => (string) $this->port,
            'DB_DATABASE' => $name,
            'DB_USERNAME' => $this->user,
            'DB_PASSWORD' => '',
            'DB_URL' => '',
            'DATABASE_URL' => '',
        ];
        $parser = new Parser;
        $entries = $parser->parse($original);
        // The parser's entry count catches multiline managed assignments that cannot be safely
        // replaced line by line, as well as lookalike assignments inside an unrelated quoted value.
        $managed = count(array_filter($entries, fn ($entry) => array_key_exists($entry->getName(), $values)));
        $updated = preg_replace('/^[\t ]*(?:export[\t ]+)?(?:'.implode('|', array_keys($values)).')[\t ]*=[^\r\n]*(?:\r?\n|$)/m', '', $original, -1, $removed);
        if ($updated === null || $managed !== $removed) {
            throw new RuntimeException('Managed env keys could not be replaced safely.');
        }

        $lineEnding = str_contains($original, "\r\n") ? "\r\n" : "\n";
        if ($updated !== '' && ! str_ends_with($updated, "\n")) {
            $updated .= $lineEnding;
        }
        foreach ($values as $key => $value) {
            $updated .= "{$key}={$value}".$lineEnding;
        }
        $unmanaged = fn ($entry) => ! array_key_exists($entry->getName(), $values);
        if (array_values(array_filter($parser->parse($updated), $unmanaged)) != array_values(array_filter($entries, $unmanaged))) {
            throw new RuntimeException('Unmanaged env entries would change.');
        }

        $tmp = tempnam(dirname($path), '.beam-walk-env-');
        if ($tmp === false) {
            throw new RuntimeException('Could not reserve a temporary env file.');
        }
        try {
            if (! chmod($tmp, 0600) || file_put_contents($tmp, $updated) !== strlen($updated)
                || $this->envPath($path) !== $path || file_get_contents($path) !== $original || ! rename($tmp, $path)) {
                throw new RuntimeException('Cannot atomically update scratch app .env.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
