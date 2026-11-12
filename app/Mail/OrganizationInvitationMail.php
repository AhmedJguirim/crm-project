<?php

namespace App\Mail;

use App\Models\OrganizationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued email sent to a user when they are invited to join an Organization.
 * Contains the accept link with the plain invitation token (the table only stores its hash).
 * Encrypted in the queue payload so the plain token is not readable in Redis or failed_jobs.
 */
class OrganizationInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrganizationInvite $invite,
        public string $token,
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
                'acceptUrl' => OrganizationInvite::acceptUrl($this->token),
                'organizationName' => $this->invite->organization->name,
                'inviterName' => $this->invite->inviter?->name,
                'role' => $this->invite->role->label(),
                'expiresAt' => $this->invite->expires_at->format('F j, Y'),
            ],
        );
    }
}
