<?php

namespace App\Console\Commands\Concerns;

trait ConfiguresEmailSyncTimeouts
{
    protected function configureEmailSyncTimeouts(): void
    {
        $imapTimeout = (int) env('EMAIL_SYNC_IMAP_TIMEOUT_SECONDS', 30);
        $smtpTimeout = (int) env('EMAIL_SYNC_SMTP_TIMEOUT_SECONDS', 30);

        if ($imapTimeout > 0 && function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, $imapTimeout);
            @imap_timeout(IMAP_READTIMEOUT, $imapTimeout);
            @imap_timeout(IMAP_WRITETIMEOUT, $imapTimeout);
            @imap_timeout(IMAP_CLOSETIMEOUT, $imapTimeout);
        }

        if ($smtpTimeout > 0) {
            @ini_set('default_socket_timeout', (string) $smtpTimeout);
            config(['mail.mailers.smtp.timeout' => $smtpTimeout]);
        }
    }
}
