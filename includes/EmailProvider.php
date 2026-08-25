<?php
/**
 * Email Provider Abstraction Layer
 * PDF Viewer Platform
 *
 * Supports multiple email providers via configuration.
 * All applications should depend on EmailProviderInterface, not concrete implementations.
 */

interface EmailProviderInterface
{
    /**
     * Send an email
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array;

    /**
     * Check if provider is configured and working
     */
    public function isHealthy(): bool;
}

/**
 * SMTP Provider — Generic SMTP/TLS/SSL support
 *
 * Speaks the SMTP protocol directly over a socket (EHLO, optional STARTTLS,
 * optional AUTH LOGIN, MAIL FROM/RCPT TO/DATA). Falls back to PHP's built-in
 * mail() only when no smtp_host is configured, so a bare install still has a
 * best-effort local delivery path without requiring any configuration.
 */
class SMTPEmailProvider implements EmailProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array {
        $from     = $this->config['from_email'] ?? 'noreply@example.com';
        $fromName = $this->config['from_name'] ?? 'PDF Viewer';
        $boundary = md5(uniqid((string)mt_rand(), true));

        $headers  = "Date: " . date('r') . "\r\n";
        $headers .= "From: " . $this->encodeHeader($fromName) . " <{$from}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "Subject: " . $this->encodeHeader($subject) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: PDF Viewer Platform\r\n";

        if (isset($options['cc'])) {
            $headers .= "Cc: {$options['cc']}\r\n";
        }

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= ($plainTextBody ?: strip_tags($htmlBody)) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // Real SMTP relay if a host is configured; otherwise fall back to mail().
        if (!empty($this->config['smtp_host'])) {
            return $this->sendViaSocket($to, $from, $headers, $body);
        }

        try {
            $success = @mail($to, $subject, $body, $headers);
            return [
                'success'  => $success,
                'message'  => $success ? 'Email sent via local mail transport.' : 'mail() returned failure — no sendmail/MTA configured and no smtp_host set.',
                'provider' => 'smtp-local',
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'provider' => 'smtp-local'];
        }
    }

    private function sendViaSocket(string $to, string $from, string $headers, string $body): array
    {
        $host       = $this->config['smtp_host'];
        $port       = (int)($this->config['smtp_port'] ?? 587);
        $username   = $this->config['smtp_username'] ?? '';
        $password   = $this->config['smtp_password'] ?? '';
        $encryption = strtolower($this->config['smtp_encryption'] ?? 'tls'); // 'tls', 'ssl', or 'none'
        $timeout    = 12;

        $transportHost = $encryption === 'ssl' ? "ssl://{$host}" : $host;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("{$transportHost}:{$port}", $errno, $errstr, $timeout);
        if (!$socket) {
            return ['success' => false, 'message' => "Could not connect to {$host}:{$port} — {$errstr} ({$errno})", 'provider' => 'smtp'];
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, 220);
            $localName = 'localhost';

            $this->command($socket, "EHLO {$localName}", 250);

            if ($encryption === 'tls') {
                $this->command($socket, "STARTTLS", 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                // Must re-EHLO after upgrading the connection.
                $this->command($socket, "EHLO {$localName}", 250);
            }

            if ($username !== '') {
                $this->command($socket, "AUTH LOGIN", 334);
                $this->command($socket, base64_encode($username), 334);
                $this->command($socket, base64_encode($password), 235);
            }

            $this->command($socket, "MAIL FROM:<{$from}>", 250);
            $this->command($socket, "RCPT TO:<{$to}>", [250, 251]);
            $this->command($socket, "DATA", 354);

            // Dot-stuff lines that start with '.' per RFC 5321, then terminate with CRLF.CRLF
            $payload = $headers . "\r\n" . $body;
            $payload = preg_replace('/\r\n\./', "\r\n..", $payload);
            fwrite($socket, $payload . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->command($socket, "QUIT", 221);
            fclose($socket);

            return ['success' => true, 'message' => 'Email sent via SMTP.', 'provider' => 'smtp'];
        } catch (Throwable $e) {
            @fclose($socket);
            return ['success' => false, 'message' => $e->getMessage(), 'provider' => 'smtp'];
        }
    }

    private function command($socket, string $line, int|array $expectedCodes): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, int|array $expectedCodes): string
    {
        $expectedCodes = (array)$expectedCodes;
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            // Multi-line SMTP responses use "code-" until the final "code ".
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP connection timed out.');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException("Unexpected SMTP response (expected " . implode('/', $expectedCodes) . "): " . trim($response));
        }
        return $response;
    }

    private function encodeHeader(string $value): string
    {
        // MIME-encode header values that contain non-ASCII characters.
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    public function isHealthy(): bool
    {
        if (empty($this->config['smtp_host'])) {
            // No SMTP host configured — healthy only if a local from_email is set (uses mail()).
            return !empty($this->config['from_email']);
        }

        $errno = 0;
        $errstr = '';
        $host = strtolower($this->config['smtp_encryption'] ?? '') === 'ssl'
            ? "ssl://{$this->config['smtp_host']}"
            : $this->config['smtp_host'];
        $socket = @stream_socket_client(
            "{$host}:" . (int)($this->config['smtp_port'] ?? 587),
            $errno,
            $errstr,
            5
        );
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }
}

/**
 * Null Provider — Logs emails without sending
 * Useful for testing/demo environments
 */
class NullEmailProvider implements EmailProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = []
    ): array {
        $logFile = $this->config['log_file'] ?? sys_get_temp_dir() . '/emails.log';
        $entry = sprintf(
            "[%s] TO: %s | SUBJECT: %s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject
        );
        @file_put_contents($logFile, $entry, FILE_APPEND);

        return [
            'success' => true,
            'message' => 'Email logged (null provider)',
            'provider' => 'null',
            'logged_to' => $logFile,
        ];
    }

    public function isHealthy(): bool
    {
        return true;
    }
}

/**
 * Email Manager Factory
 */
class EmailManager
{
    private static ?EmailProviderInterface $provider = null;

    public static function setProvider(EmailProviderInterface $provider): void
    {
        self::$provider = $provider;
    }

    public static function getProvider(array $config): EmailProviderInterface
    {
        if (self::$provider) {
            return self::$provider;
        }

        $providerType = $config['email_provider'] ?? 'smtp';

        return match ($providerType) {
            'null' => new NullEmailProvider($config),
            default => new SMTPEmailProvider($config),
        };
    }

    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainTextBody = '',
        array $options = [],
        array $config = []
    ): array {
        $provider = self::getProvider($config);
        return $provider->send($to, $subject, $htmlBody, $plainTextBody, $options);
    }
}
