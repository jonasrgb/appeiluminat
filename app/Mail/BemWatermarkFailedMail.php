<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BemWatermarkFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $context)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BEM Watermark job failed',
        );
    }

    public function build()
    {
        return $this->view('emails.bem-watermark-failed')
            ->with(['context' => $this->context]);
    }
}
