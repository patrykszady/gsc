<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Yelp browser-automation job determines that the persistent
 * session in the user-data-dir is no longer authenticated (e.g. cookies
 * expired, manually logged out, DataDome invalidated the session).
 *
 * Caught by job handlers so they stop retrying immediately and surface a
 * clear "re-login via /admin/platforms" status to the admin instead of
 * burning queue retries against /login (which DataDome will always block
 * for unattended automation).
 */
class YelpSessionExpiredException extends RuntimeException
{
}
