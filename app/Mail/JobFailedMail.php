<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class JobFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $errorOutput;

    /**
     * Create a new message instance.
     *
     * @param  string  $errorOutput
     * @return void
     */
    public function __construct($errorOutput)
    {
        $this->errorOutput = $errorOutput;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Job Failed Mail',
        );
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->view('emails.jobfailed') // Vizualizarea care va fi trimisă
                    ->with([
                        'errorOutput' => $this->errorOutput, // trimitem eroarea către vizualizare
                    ]);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
