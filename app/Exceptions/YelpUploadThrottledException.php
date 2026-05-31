<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by YelpBusinessService when a Yelp browser-automation run is
 * skipped because:
 *   - another run is currently in progress (lock contention), OR
 *   - the configured minimum interval between runs has not yet elapsed.
 *
 * Queue jobs catch this and release themselves back to the queue with a
 * delay equal to `retry_after_seconds` instead of failing the job.
 */
class YelpUploadThrottledException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }
}
