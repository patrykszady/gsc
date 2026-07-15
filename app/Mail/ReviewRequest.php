<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReviewRequest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public string $reviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'How did we do? — GS Construction',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.review-request',
            with: [
                'name' => $this->project->client_name,
                'projectTitle' => $this->project->title,
                'location' => $this->project->location,
                'reviewUrl' => $this->reviewUrl,
            ],
        );
    }
}
