<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParentProductBootstrapIssueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $context)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Parentproduct bootstrap issue',
        );
    }

    public function build()
    {
        return $this->view('emails.parentproduct-bootstrap-issue')
            ->with(['context' => $this->context]);
    }
}
