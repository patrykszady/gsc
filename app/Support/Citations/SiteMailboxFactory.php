<?php

namespace App\Support\Citations;

use SsSystems\Platform\Citations\Contracts\Mailbox;
use SsSystems\Platform\Citations\Mailbox\GraphMailbox;
use SsSystems\Platform\Citations\Mailbox\ImapMailbox;
use SsSystems\Platform\Citations\Mailbox\UnconfiguredMailbox;

/**
 * Reads `config('citations.inbox.*')` exactly as the site's former
 * `App\Services\Citations\VerificationInbox::mode()` did, and builds
 * whichever `Contracts\Mailbox` implementation fits — the ONE place on
 * this site that still reads those config keys; the kit's mailbox
 * classes take them as constructor arguments instead (kit 0.13.0's
 * `Contracts\Mailbox` docblock). Called fresh from
 * `AppServiceProvider`'s binding closure on every `app()->make()`, never
 * cached, so a test that changes config between assertions sees it.
 */
class SiteMailboxFactory
{
    public static function current(): Mailbox
    {
        $graph = (array) config('citations.inbox.graph', []);
        $mailbox = (string) config('citations.inbox.mailbox');
        if ((string) ($graph['tenant_id'] ?? '') !== '' && (string) ($graph['client_id'] ?? '') !== '' && (string) ($graph['client_secret'] ?? '') !== '' && $mailbox !== '') {
            return new GraphMailbox($mailbox, (string) $graph['tenant_id'], (string) $graph['client_id'], (string) $graph['client_secret']);
        }

        if (function_exists('imap_open') && (string) config('citations.inbox.password') !== '' && (string) config('citations.inbox.user') !== '') {
            return new ImapMailbox(
                (string) config('citations.inbox.host'),
                (int) config('citations.inbox.port'),
                (string) config('citations.inbox.folder'),
                (string) config('citations.inbox.user'),
                (string) config('citations.inbox.password'),
            );
        }

        return new UnconfiguredMailbox;
    }
}
