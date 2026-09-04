<?php

namespace Splicewire\Beam\Dev\Load;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * How many connections the database server was carrying while a load run was in flight.
 *
 * ## Why a latency reading without this is not worth quoting
 *
 * At concurrency of 8 and up against one local Postgres, the contended resource stops being the
 * application and becomes the cluster — and the reading does not say so. This estate has the
 * measurement already: two concurrent schema-creating suites exhausted one local Postgres with
 * `SQLSTATE[53200] out of shared memory`, killing ~60 tests including pure unit tests that touch no
 * database, and the run read **196 failed against a true figure of ~132**. Every session had its own
 * database and it made no difference, because the contended resource was the server.
 *
 * A load run is the same shape with the failure mode inverted: instead of phantom failures it
 * produces plausible latency, which is worse, because nothing about it looks wrong. So every reading
 * carries the connection count beside it, and a run that could not take one says `null` rather than
 * `0` — "I did not look" must never render as "nothing there".
 */
class ContentionProbe
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Server-side connection count, or null when the driver cannot answer.
     *
     * Asked of the SERVER, not inferred from the pool this process holds. A per-process count would
     * report 1 while eight sibling workers saturated the cluster — the precise reading that makes
     * contention invisible.
     */
    public function connections(?string $connection = null): ?int
    {
        try {
            $conn = $this->db->connection($connection);

            return match ($conn->getDriverName()) {
                'pgsql' => (int) $conn->scalar(
                    'select count(*) from pg_stat_activity where datname = current_database()'
                ),
                'mysql' => (int) $conn->scalar(
                    'select count(*) from information_schema.processlist where db = database()'
                ),
                // sqlite has no server and therefore no contention of this kind. Null, not zero:
                // the question does not apply rather than answering itself in the negative.
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
