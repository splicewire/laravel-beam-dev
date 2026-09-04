<?php

namespace Splicewire\Beam\Dev\Load;

/**
 * One unit of work a load run drives, named.
 *
 * Deliberately opaque: beam-dev is family-blind by charter, so this contract knows a label and a
 * callable and nothing else. Whether the work is an HTTP request, a particle operation, a query or a
 * queue job is the CALLER's vocabulary, and teaching this package any of it would invert the
 * dependency direction the package exists inside.
 */
interface LoadTarget
{
    /** A stable, human-readable name. It appears in every reading, so make it greppable. */
    public function label(): string;

    /**
     * Do the work once. Throwing is a legitimate outcome — a load run needs the error rate, so the
     * runner catches and records rather than aborting.
     */
    public function run(): void;
}
