<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Stale / recovered feed alert (D-041). No secrets: times, the last outcome
 * and a link to the hub.
 */
class FeedStateMail extends Mailable
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $lastGoodPoll,
        public readonly ?string $lastOutcome,
        public readonly ?string $lastPollAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->state === 'stale'
            ? 'RAC Flight Data Hub: AODB feed is stale'
            : 'RAC Flight Data Hub: AODB feed recovered');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.feed-state', with: ['link' => url('/')]);
    }
}
