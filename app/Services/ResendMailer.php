<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ResendMailer
{
    private string $apiKey;
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->apiKey   = env('RESEND_API_KEY', '');
        $this->from     = env('MAIL_FROM_ADDRESS', 'noreply@nwafizlogi.com');
        $this->fromName = env('MAIL_FROM_NAME', 'نوافذ');
    }

    /**
     * Send email — tries Resend API first, falls back to PHP mail().
     */
    public function send(string $to, string $subject, string $html): bool
    {
        // 1. Try Resend HTTP API (uses HTTPS/443 — not blocked like SMTP)
        if ($this->apiKey && $this->sendViaResend($to, $subject, $html)) {
            return true;
        }

        // 2. Fallback: PHP built-in mail()
        return $this->sendViaPhpMail($to, $subject, $html);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function sendViaResend(string $to, string $subject, string $html): bool
    {
        try {
            $payload = json_encode([
                'from'    => "{$this->fromName} <{$this->from}>",
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $html,
            ]);

            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload),
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body    = curl_exec($ch);
            $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                Log::warning('ResendMailer: curl error — ' . $curlErr);
                return false;
            }

            if ($status >= 200 && $status < 300) {
                Log::info('ResendMailer [Resend API]: sent to ' . $to);
                return true;
            }

            Log::warning('ResendMailer [Resend API]: HTTP ' . $status . ' — ' . $body);
            return false;

        } catch (\Throwable $e) {
            Log::warning('ResendMailer [Resend API] exception: ' . $e->getMessage());
            return false;
        }
    }

    private function sendViaPhpMail(string $to, string $subject, string $html): bool
    {
        try {
            $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromEncoded    = '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->from . '>';

            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'From: ' . $fromEncoded,
                'Reply-To: ' . $this->from,
                'X-Mailer: PHP/' . phpversion(),
            ]);

            $body   = chunk_split(base64_encode($html));
            $result = mail($to, $subjectEncoded, $body, $headers, '-f' . $this->from);

            if ($result) {
                Log::info('ResendMailer [PHP mail()]: sent to ' . $to);
                return true;
            }

            Log::error('ResendMailer [PHP mail()]: mail() returned false for ' . $to);
            return false;

        } catch (\Throwable $e) {
            Log::error('ResendMailer [PHP mail()] exception: ' . $e->getMessage());
            return false;
        }
    }
}
