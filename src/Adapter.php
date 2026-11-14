<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * An importer wrapped as a rule runner.
 *
 * Return a RunResult, an item count, true or null for success, false or a
 * WP_Error for failure. Throwing fails the run and the tick moves on.
 * Loop over items in small steps and check RunContext::shouldStop() between
 * them. Give every network call a timeout.
 */
interface Adapter
{
    /**
     * @return RunResult|int|bool|null|\WP_Error
     */
    public function run(RunContext $context);
}
