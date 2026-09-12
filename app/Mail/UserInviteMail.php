<?php

namespace App\Mail;

use App\Models\UserInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued, not sent in-request. The invite was created and then the admin's
 * request blocked on the SMTP handshake; an SMTP failure surfaced as a 500
 * *after* the invite row had been written, leaving an invite in the database
 * that nobody ever received. "Created" and "delivered" are now independently
 * retryable.
 */
class UserInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserInvite $invite,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invite',
            with: [
                'inviteUrl'   => route('invite.show', $this->invite->token),
                'inviterName' => $this->invite->inviter?->name ?? config('app.name'),
                'firstName'   => $this->invite->first_name,
                'expiresAt'   => $this->invite->expires_at,
                'appName'     => config('app.name'),
            ],
        );
    }
}
