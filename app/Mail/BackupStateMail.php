<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Stale / recovered backup alert (D-058). No secrets: the newest backup's time and a pointer
 * to the runbook.
 */
class BackupStateMail extends Mailable
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $newestBackup,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->state === 'stale'
            ? 'RAC Flight Data Hub: database backup is stale'
            : 'RAC Flight Data Hub: database backup recovered');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.backup-state', with: ['hours' => (int) config('backup.stale_after_hours', 26)]);
    }
}
