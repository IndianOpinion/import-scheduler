<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Optional: an adapter that knows its own human label.
 */
interface DescribesItself
{
    public function label(): string;
}
