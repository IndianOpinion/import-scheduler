<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Exception;

/**
 * A lock could not be handled as asked (for example, an unlock of a held lock without force).
 */
class LockException extends ImportSchedulerException
{
}
