<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The plaintext credential is carried on the mailable (never stored)
     * so tests can assert generated-vs-provided invite paths.
     */
    public function __construct(
        public User $staff,
        public string $plainPassword,
        public string $loginUrl,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ControlDesk staff account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invite',
        );
    }
}
