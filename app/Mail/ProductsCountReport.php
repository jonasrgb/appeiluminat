<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductsCountReport extends Mailable
{
    use Queueable, SerializesModels;

    public $reports;

    /**
     * Create a new message instance.
     */
    public function __construct($reports)
    {
        $this->reports = $reports;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Raport Produse - Shopify')
                    ->markdown('emails.products_count_report')
                    ->with([
                        'reports' => $this->reports,
                    ]);
    }
}
