<?php

namespace App\Console\Commands;

use App\Models\EmailSyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SyncInboxEmailsRaw extends Command
{
    protected $signature = 'emails:sync-inbox-raw';
    protected $description = 'Proceseaza DOAR emailurile noi din INBOX (IMAP raw) si le afiseaza in log daca nu se incadreaza in regulile de ignorare';

    public function handle(): int
    {
        $this->info('Pornesc sync INBOX RAW (numai emailuri noi)...');
        //Log::info('emails:sync-inbox-raw a pornit', ['time' => now()->toDateTimeString()]);
        $delayMs = (int) env('MINICRM_DELAY_MS', 1000);
        $forwardTo = trim((string) env('FORWARD_TO_EMAIL', ''));

        $host     = env('IMAP_HOST', 'imap.gmail.com');
        $port     = (int) env('IMAP_PORT', 993);
        $username = env('IMAP_USERNAME');
        $password = env('IMAP_PASSWORD');

        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $host, $port);

        $stream = @imap_open($mailbox, $username, $password);

        if ($stream === false) {
            $this->error('IMAP connect error: '.imap_last_error());
            return self::FAILURE;
        }

        $mailboxKey = 'gmail-INBOX';

        // salvam DOAR last_uid, nu emailurile
        $state = EmailSyncState::firstOrCreate(
            ['mailbox' => $mailboxKey],
            ['last_uid' => null]
        );

        $lastUid = $state->last_uid;

        // 1) Prima rulare: bootstrap – NU procesam istoric
        if ($lastUid === null) {
            $this->info('Prima rulare: fac bootstrap la last_uid fara sa procesez istoricul...');

            $uids = imap_search($stream, 'ALL', SE_UID);

            if ($uids === false || count($uids) === 0) {
                $this->warn('Nu exista mesaje in INBOX.');
                imap_close($stream);
                return self::SUCCESS;
            }

            sort($uids);
            $maxUid = end($uids);

            $state->last_uid = $maxUid;
            $state->save();

            $this->info('Bootstrap complet. last_uid setat la: '.$maxUid);
            imap_close($stream);
            return self::SUCCESS;
        }

        // 2) Rularile urmatoare: doar UID > last_uid
        $this->info('Caut mesaje noi (UID > '.$lastUid.')...');

        $uids = imap_search($stream, 'ALL', SE_UID);

        if ($uids === false || count($uids) === 0) {
            $this->info('Nu exista mesaje in INBOX.');
            imap_close($stream);
            return self::SUCCESS;
        }

        sort($uids);

        $newUids = array_filter($uids, function ($uid) use ($lastUid) {
            return (int) $uid > (int) $lastUid;
        });

        if (empty($newUids)) {
            $this->info('Nu exista emailuri noi.');
            imap_close($stream);
            return self::SUCCESS;
        }

        $this->info('Gasite emailuri noi: '.count($newUids));

        $processed   = 0;
        $ignored     = 0;
        $maxUid      = $lastUid;

        foreach ($newUids as $uid) {
            $uid = (int) $uid;

            if ($uid > $maxUid) {
                $maxUid = $uid;
            }

            $overview = imap_fetch_overview($stream, (string) $uid, FT_UID);
            if (!$overview || !isset($overview[0])) {
                continue;
            }
            $ov = $overview[0];

            $rawSubject = $ov->subject ?? '';
            $subject    = $rawSubject ? imap_utf8($rawSubject) : '';
            $fromHeader = $ov->from ?? '';
            $fromEmail  = $this->extractEmailAddress($fromHeader);
            $date       = $ov->date ?? '';

            /*
             * MODIFICARE:
             * 1) Verificam daca emailul trebuie ignorat INAINTE sa citim body-ul.
             *    Astfel, pentru cele ignorate nu se mai apeleaza getPlainTextBody(),
             *    deci nu se marcheaza ca read in Gmail.
             */
            if ($this->shouldIgnore($fromEmail, $subject)) {
                $ignored++;
                continue;
            }

            // 2) DOAR pentru emailurile NE-ignorate citim body-ul
            $body = $this->getPlainTextBody($stream, $uid);

            $processed++;

            // LOG in Laravel + afisare in consola
            $logData = [
                'uid'     => $uid,
                'from'    => $fromEmail,
                'subject' => $subject,
                'date'    => $date,
                'body'    => mb_substr($body, 0, 2000), // sa nu fie imens in log
            ];

            Log::info('Email NOU care nu a fost ignorat', $logData);

            $this->line(str_repeat('=', 60));
            $this->info('EMAIL NOU Procesat (NE-IGNORAT):');
            $this->line('UID:     '.$uid);
            $this->line('From:    '.$fromEmail);
            $this->line('Subject: '.$subject);
            $this->line('Date:    '.$date);
            $this->line('Body (primele 500 caractere):');
            $this->line(mb_substr($body, 0, 500));

            // === TRIMITERE IN MINICRM ===
            //$result = $this->sendToMiniCrm($fromEmail, $subject, $body);

            // if ($result['ok']) {
            //     $this->info('MiniCRM: inserare reusita: '.$result['info']);
            // } else {
            //     $this->error('MiniCRM: EROARE - '.$result['error']);
            // }

            if (!empty($forwardTo)) {
                $fwdOk = $this->forwardEmail($forwardTo, $fromEmail, $subject, $date, $body);

                if ($fwdOk) {
                    $this->info('Forward trimis catre '.$forwardTo);
                } else {
                    $this->error('Forward NU a putut fi trimis catre '.$forwardTo);
                }
            }
            // Delay intre request-uri (anti-abuz)
            if ($delayMs > 0) {
                usleep($delayMs * 1000); // ms -> microsecunde
            }
        }

        if ($maxUid > $lastUid) {
            $state->last_uid = $maxUid;
            $state->save();
        }

        imap_close($stream);

        $this->info("Emailuri noi procesate (ne-ignorate): {$processed}");
        $this->info("Emailuri ignorate conform regulilor: {$ignored}");
        $this->info("Noul last_uid: {$state->last_uid}");

        return self::SUCCESS;
    }

    /**
     * Reguli de IGNORARE:
     *  - de la anumiti senders
     *  - sau subiectul contine 'FAN Courier'
     */
    protected function shouldIgnore(?string $fromEmail, ?string $subject): bool
    {
        $fromEmail = strtolower(trim($fromEmail ?? ''));
        $subject   = strtolower($subject ?? '');

        // lista de adrese ignorate explicit
        $blockedSendersExact = [
            'lustreledro@gmail.com',
            'no-reply@loox.io',
            'mailer-daemon@googlemail.com',
            'recommendations@discover.pinterest.com',
            // 'noreply@xconnector.app',
            'noreply@euplatesc.ro',
            'noreply@info.pinterest.com',
            'noreply@business-updates.facebook.com',
            'borderoutrimiteri@fancourier.ro',
            'office@lustreled.ro',
            'no-reply@accounts.google.com',
            'easybox@sameday.ro'
        ];

        // 1) daca e una din adresele exacte
        if (in_array($fromEmail, $blockedSendersExact, true)) {
            return true;
        }

        // 2) orice email de la domeniul FAN Courier
        if (str_contains($fromEmail, '@fancourier.ro')) {
            return true;
        }

        // // 3) subiect contine "fan courier"
        // if (str_contains($subject, 'fan courier')) {
        //     return true;
        // }

        // // 4) subiect contine "awb"
        // if (str_contains($subject, 'awb')) {
        //     return true;
        // }

        return false;
    }

    /**
     * Extrage adresa de email din header-ul "From: Name <mail@example.com>"
     */
    protected function extractEmailAddress(string $fromHeader): ?string
    {
        if (preg_match('/<([^>]+)>/', $fromHeader, $matches)) {
            return $matches[1];
        }

        return trim($fromHeader);
    }

    /**
     * Extrage body-ul in format text/plain (decodat).
     * Daca nu gaseste text/plain, incearca text/html si da strip_tags().
     */
    protected function getPlainTextBody($stream, int $uid): string
    {
        $structure = imap_fetchstructure($stream, (string) $uid, FT_UID);

        if (!$structure) {
            return '';
        }

        // 1) incearca sa gaseasca text/plain in structura MIME
        $plain = $this->findPartBody($stream, $uid, $structure, 'PLAIN');

        if ($plain !== null && $plain !== '') {
            return trim($plain);
        }

        // 2) fallback: incearca text/html si scoate tag-urile
        $html = $this->findPartBody($stream, $uid, $structure, 'HTML');

        if ($html !== null && $html !== '') {
            return trim(strip_tags($html));
        }

        // 3) fallback final: body brut
        $raw = imap_body($stream, (string) $uid, FT_UID);
        if ($raw === false) {
            return '';
        }

        return trim(strip_tags($raw));
    }

    /**
     * Cauta in structura MIME o parte de tip text/$subtype (PLAIN sau HTML)
     * si intoarce continutul decodat.
     */
    protected function findPartBody($stream, int $uid, \stdClass $structure, string $subtype, string $partNumber = ''): ?string
    {
        $subtype = strtoupper($subtype);

        // Daca nu este multipart
        if (!isset($structure->parts) || empty($structure->parts)) {
            if ($structure->type == TYPETEXT && strtoupper($structure->subtype ?? '') === $subtype) {
                $pn   = $partNumber !== '' ? $partNumber : '1';
                $data = imap_fetchbody($stream, (string) $uid, $pn, FT_UID);
                return $this->decodePartBody($data, $structure->encoding ?? 0);
            }

            return null;
        }

        // multipart: iteram recursiv prin parts
        $index = 1;
        foreach ($structure->parts as $part) {
            $pn = $partNumber === '' ? (string) $index : $partNumber.'.'.$index;

            if ($part->type == TYPETEXT && strtoupper($part->subtype ?? '') === $subtype) {
                $data    = imap_fetchbody($stream, (string) $uid, $pn, FT_UID);
                $decoded = $this->decodePartBody($data, $part->encoding ?? 0);
                if ($decoded !== null && $decoded !== '') {
                    return $decoded;
                }
            }

            if (isset($part->parts) && !empty($part->parts)) {
                $result = $this->findPartBody($stream, $uid, $part, $subtype, $pn);
                if ($result !== null && $result !== '') {
                    return $result;
                }
            }

            $index++;
        }

        return null;
    }

    /**
     * Decodeaza partea in functie de encoding (base64, quoted-printable, etc.)
     */
    protected function decodePartBody($data, int $encoding): ?string
    {
        if ($data === false || $data === null) {
            return null;
        }

        switch ($encoding) {
            case ENCBASE64:
                return base64_decode($data);
            case ENCQUOTEDPRINTABLE:
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    protected function forwardEmail(
        string $forwardTo,
        string $originalFrom,
        string $originalSubject,
        string $originalDate,
        string $body
    ): bool {
        try {
            // optional: adresa ta, doar pentru header "Sender" / tracking
            $systemAddress = config('mail.from.address');
            $systemName    = config('mail.from.name', 'Lustreled');

            $subject = $originalSubject;
            $text    = $body; // DOAR continutul original

            Mail::raw($text, function ($message) use ($forwardTo, $originalFrom, $systemAddress, $systemName, $subject) {
                $message->to($forwardTo)
                    // cheie: CRM va considera acest "From" ca fiind clientul
                    ->from($originalFrom)
                    // optional: cine a facut redirect-ul
                    ->sender($systemAddress, $systemName)
                    ->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Eroare la forwardEmail: '.$e->getMessage(), [
                'forward_to'       => $forwardTo,
                'original_from'    => $originalFrom,
                'original_subject' => $originalSubject,
            ]);
            return false;
        }
    }

    protected function sendToMiniCrm(string $email, string $subject, string $body): array
    {
        $endpoint   = env('MINICRM_ENDPOINT', 'https://r3.minicrm.ro/Api/Signup');
        $formHash   = env('MINICRM_FORM_HASH', '76759-00t2jcf0lr1ilv94x5gx1v2yhxh6s8');
        $signupPage = env('MINICRM_SIGNUP_PAGE', 'https://lustreled.ro/email-from-gmail');

        $payload = [
            'Contact[3353][Email]'    => $email,
            'Contact[3353][LastName]' => mb_substr($subject, 0, 255),
            'ToDo[3355][Comment]'     => mb_substr($body, 0, 2000),
            'Dummy[]'                 => 1,
            'GDPR_Contribution[]'     => 1,
            'SignupPage'             => $signupPage,
            'Referrer'               => '',
            'FormHash'               => $formHash,
        ];

        try {
            $response = Http::asForm()->post($endpoint, $payload);

            if (!$response->successful()) {
                return [
                    'ok'    => false,
                    'error' => 'HTTP status '.$response->status(),
                ];
            }

            $json = $response->json();

            if (!empty($json['Error'])) {
                return [
                    'ok'    => false,
                    'error' => (string) $json['Error'],
                ];
            }

            return [
                'ok'   => true,
                'info' => $json['Info'] ?? 'Datele au fost procesate cu succes.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'    => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
