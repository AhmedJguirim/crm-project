<?php

namespace App\Mail;

use App\Models\OrganizationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
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

    /**
     * Re-checked when the queued mail is delivered: nothing is sent once the invitation is no longer valid or its organization was deleted.
     *
     * @param  Factory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $invite = $this->invite->fresh(['organization']);

        if ($invite === null || ! $invite->isValid() || $invite->organization === null) {
            return null;
        }

        $this->invite = $invite;

        return parent::send($mailer);
    }

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
