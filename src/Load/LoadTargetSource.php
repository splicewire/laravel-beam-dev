<?php

namespace Splicewire\Beam\Dev\Load;

use Splicewire\Beam\Dev\Console\LoadCommand;

/**
 * The port through which a load run learns what to drive.
 *
 * beam-dev ships **no implementation**. That is the point: the set of operations worth measuring is a
 * fact about the host's own declared surface, and reading a family registry from here would make this
 * package depend upward on the family it is a development tool for.
 *
 * A consumer binds one implementation. The estate's own adapter derives its targets from
 * `ParticleOperationRegistry` / `ParticleResourceRegistry` rather than a hand-written list — a
 * hard-coded list silently stops covering operations added after it was written, which is the
 * failure this port's shape is meant to discourage.
 *
 * ⚠️ Nothing binds this by default, and that is why {@see LoadCommand}
 * FAILS rather than reporting an empty run when it is unbound. "No targets declared" and "no target
 * source installed" must not produce the same output.
 */
interface LoadTargetSource
{
    /**
     * Every target this host offers, keyed by label.
     *
     * @return array<string, LoadTarget>
     */
    public function targets(): array;
}
