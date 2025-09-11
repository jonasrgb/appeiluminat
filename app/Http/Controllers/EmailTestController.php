<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Mail\JobFailedMail;
use Illuminate\Support\Facades\Mail;

class EmailTestController extends Controller
{
    public function sendTestEmail()
    {
        // Simulăm un mesaj de eroare
        $errorOutput = "Eroare de test în cron job!";

        // Trimitem email-ul
        Mail::to('mitnickoff121@gmail.com')->send(new JobFailedMail($errorOutput));

        return "Email-ul de test a fost trimis!";
    }
}
