<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin alert: the Yelp for Business browser session died and photo uploads
 * are paused until someone re-logs-in on /admin/{site}/platforms.
 *
 * Sent by YelpBusinessService::markSessionDead(), throttled to once per
 * episode. A Mailable (not Mail::raw) so tests can assert on it.
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
            subject: 'Yelp session expired — photo uploads paused',
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
