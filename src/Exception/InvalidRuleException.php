<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Exception;

/**
 * A rule registration was rejected (bad id, lane, interval, runtime or runner).
 */
class InvalidRuleException extends ImportSchedulerException
{
}
