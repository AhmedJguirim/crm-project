<?php

namespace App\Mail;

use App\Models\OrganizationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued email sent to a user when they are invited to join an Organization.
 * Contains the accept link with the unique invitation token.
 */
class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrganizationInvite $invite,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->invite->organization->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.organization-invitation',
            with: [
                'acceptUrl' => OrganizationInvite::acceptUrl($this->invite->token),
                'organizationName' => $this->invite->organization->name,
                'inviterName' => $this->invite->inviter->name,
                'role' => $this->invite->role->label(),
                'expiresAt' => $this->invite->expires_at->format('F j, Y'),
            ],
        );
    }
}
