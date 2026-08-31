<?php

namespace Splicewire\Beam\Dev\Databases;

/**
 * Reading provisioning SQL well enough to say WHICH statement failed, and what to do about it.
 *
 * The whole value of provisioning a scratch database is destroyed by a failure nobody can act on.
 * Running a file through one `unprepared()` call gives you a PDO message about a control file on a
 * path that means nothing to the caller; running it statement by statement lets the tool say
 * `vector` and name the thing to install. That difference is the reason this class exists — it is
 * not a SQL parser and does not try to be one.
 *
 * The splitter is deliberately naive: semicolon-terminated statements, comments stripped. That is
 * exactly right for the file this package ships (five `CREATE EXTENSION` lines) and exactly wrong
 * for anything with a dollar-quoted function body in it, which is why statement-wise execution is
 * only ever used for THIS package's own default file. A project that declares its own init SQL gets
 * the whole file in one call, unchanged.
 */
class ProvisioningSql
{
    /**
     * Split provisioning SQL into executable statements, dropping comments and blank lines.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $stripped = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map(trim(...), explode(';', $stripped)),
            static fn (string $statement) => $statement !== '',
        ));
    }

    /**
     * The extension a `CREATE EXTENSION` statement names, or null if the statement is something else.
     *
     * Quoting matters here and is easy to lose: `"uuid-ossp"` is quoted precisely because it is not a
     * bare identifier, so a pattern that only matches word characters silently skips the one
     * extension whose name needs the quotes.
     */
    public static function extensionIn(string $statement): ?string
    {
        $matched = preg_match(
            '/\bCREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|([A-Za-z0-9_]+))/i',
            $statement,
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        return ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? null);
    }

    /**
     * What a caller has to install to make $extension available, in the terms they would search for.
     *
     * Generic advice ("install the extension") is the same as no advice. `vector` is the one that is
     * genuinely a separate install rather than part of contrib, and it is also the one that took
     * tower's suite down, so it gets its own line.
     */
    public static function hintFor(string $extension): string
    {
        return match (strtolower($extension)) {
            'vector' => 'pgvector is a separate install: `brew install pgvector` against the Postgres '
                .'you are running (Laravel Herd\'s included), or use the pgvector/pgvector image.',
            'uuid-ossp', 'citext', 'pg_trgm', 'fuzzystrmatch', 'pgcrypto', 'unaccent', 'hstore', 'btree_gin', 'btree_gist' => 'ships in PostgreSQL contrib — install the `postgresql-contrib` package for this server '
                .'(the official postgres images already carry it).',
            default => 'the server does not have this extension available; install it, or drop it from '
                .'the provisioning SQL.',
        };
    }
}
