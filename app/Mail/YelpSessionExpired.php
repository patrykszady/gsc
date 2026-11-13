<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin alert: the Yelp for Business browser session died, so the lead sync
 * and photo uploads are stopped until someone re-logs-in on
 * /admin/{site}/platforms.
 *
 * Sent by YelpBusinessService::notifySessionDead(), once a day for as long
 * as the session stays dead. A Mailable (not Mail::raw) so tests can assert
 * on it.
 */
class YelpSessionExpired extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $note,
        public string $platformsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Yelp session expired — leads and photo uploads paused',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.yelp-session-expired',
            with: [
                'note' => $this->note,
                'platformsUrl' => $this->platformsUrl,
            ],
        );
    }
}
